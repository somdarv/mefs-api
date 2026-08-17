<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\CycleStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Payments\MomoInstruction;
use App\Services\Payments\PaymentInitiator;
use App\Services\Payments\PaystackClient;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The charge that asks for a code instead of buzzing, and the wallet lookup in front of it.
 *
 * ⚠️ THE BUG THESE PIN. `PaymentInitiator` used to inspect `failed` and treat every other
 * charge status as a pushed prompt. On the Ghana networks and accounts that answer `send_otp`,
 * Paystack had texted the customer a code and was waiting for `/charge/submit_otp` — so the
 * screen said "approve the prompt on your phone" while the customer held a code nothing would
 * ever ask for, and the charge sat unfinished until it expired. Every symptom of a dead
 * integration, out of one unhandled branch.
 *
 * ⚠️ AND NOTHING HERE HAS MET THE LIVE GATEWAY. The request shapes for `/charge/submit_otp`
 * and `/bank/resolve` come from Paystack's documentation, exactly like `SmsOnlineGhSender`.
 * What these prove is our half: that the statuses are branched on rather than lumped together,
 * that a rejected code leaves the charge open, that submitting a code never marks an order
 * paid, and that a lookup which fails costs a confirmation step and never a sale.
 */
final class MomoOtpTest extends TestCase
{
    use RefreshDatabase;

    private CycleDay $day;

    private MenuOption $waakye;

    private const SECRET = 'sk_test_pretend';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));

        $cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ]);
        $cycle->update(['status' => CycleStatus::Published->value]);

        $this->day = $cycle->days()->orderBy('date')->firstOrFail();

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
            ['is_available' => true],
        );

        $this->waakye = $item->options()->firstOrFail();
    }

    private function withKeys(): void
    {
        config(['paystack.secret_key' => self::SECRET]);
        $this->app->forgetInstance(PaystackClient::class);
    }

    private function order(): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, 2)],
            type: OrderType::Pickup,
            source: OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: '0241234567',
            cycleDayId: $this->day->id,
        ));
    }

    private function fakeCharge(string $status): void
    {
        Http::fake(['*/charge' => Http::response([
            'status' => true,
            'data' => ['status' => $status, 'display_text' => 'A code has been sent to you'],
        ], 200)]);
    }

    private function begin(Order $order)
    {
        return app(PaymentInitiator::class)->begin($order, MomoInstruction::from('0241234567'));
    }

    // ── The status branch ─────────────────────────────────────────────────────

    public function test_a_charge_asking_for_a_code_is_not_reported_as_a_prompt(): void
    {
        $this->withKeys();
        $this->fakeCharge('send_otp');

        $attempt = $this->begin($this->order());

        $this->assertTrue($attempt->needsOtp(), 'send_otp must produce an otp_required attempt');
        $this->assertFalse($attempt->wasPrompted(), 'nothing was pushed to the handset');
        $this->assertTrue($attempt->isUnderway(), 'the charge has still started');
        $this->assertSame('otp_required', $attempt->toArray()['state']);
    }

    public function test_a_pushed_prompt_still_reports_as_one(): void
    {
        $this->withKeys();
        $this->fakeCharge('pay_offline');

        $attempt = $this->begin($this->order());

        $this->assertTrue($attempt->wasPrompted());
        $this->assertFalse($attempt->needsOtp());
        $this->assertSame('prompted', $attempt->toArray()['state']);
    }

    /**
     * ⚠️ THE REGRESSION GUARD, NOT A CURIOSITY. `send_pin` and friends belong to Paystack's
     * card flow, which this integration deliberately cannot reach. Lumping them in with
     * "waiting" is the exact shape of the `send_otp` bug, and the only way it cannot come back
     * is for an unrecognised instruction to be a refusal that names itself.
     */
    public function test_a_card_flow_status_is_refused_rather_than_left_waiting(): void
    {
        $this->withKeys();
        $this->fakeCharge('send_pin');

        $attempt = $this->begin($this->order());

        $this->assertFalse($attempt->isUnderway());
        $this->assertSame('unsupported_status_send_pin', $attempt->reason);
        $this->assertNull($attempt->toArray(), 'a refused attempt carries no payment payload');
    }

    // ── Handing the code back ─────────────────────────────────────────────────

    public function test_submitting_the_code_does_not_itself_mark_the_order_paid(): void
    {
        $this->withKeys();
        $this->fakeCharge('send_otp');

        $order = $this->order();
        $attempt = $this->begin($order);
        $payment = $attempt->payment;

        /*
         * Paystack answers the submission with `success` outright, which is precisely the
         * temptation. `PaymentRecorder` is the single writer of `orders.is_paid` — it owns the
         * row lock, the amount check and the idempotency — so the submission must go through
         * verify rather than writing the row itself.
         */
        Http::fake([
            '*/charge/submit_otp' => Http::response([
                'status' => true,
                'data' => ['status' => 'success'],
            ], 200),
            // Shaped like Paystack's own verify payload — `paid_at` included, because the
            // recorder reads the settlement time off it rather than off our clock.
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => $payment->amount,
                    'paid_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/otp", [
            'reference' => $payment->reference,
            'otp' => '123456',
        ]);

        $response->assertOk();
        $order->refresh();
        $payment->refresh();

        $this->assertTrue($order->is_paid, 'verify settled it through the one writer');
        // The settlement time is the PAYMENT's, not the order's — one attempt is what got
        // paid, and an order can carry more than one.
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
    }

    public function test_a_rejected_code_leaves_the_charge_open_for_another_try(): void
    {
        $this->withKeys();
        $this->fakeCharge('send_otp');

        $order = $this->order();
        $payment = $this->begin($order)->payment;
        $statusBefore = $payment->status;

        Http::fake(['*/charge/submit_otp' => Http::response([
            'status' => false,
            'message' => 'Invalid OTP',
        ], 200)]);

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/otp", [
            'reference' => $payment->reference,
            'otp' => '000000',
        ])->assertStatus(422);

        $payment->refresh();
        $order->refresh();

        // A typo must not cost a charge. Closing the attempt here would force the customer to
        // start a second one for the same order.
        $this->assertSame($statusBefore, $payment->status);
        $this->assertFalse($order->is_paid);
    }

    public function test_a_reference_from_another_order_is_a_404(): void
    {
        $this->withKeys();
        $this->fakeCharge('send_otp');

        $mine = $this->order();
        $theirs = $this->order();
        $theirPayment = $this->begin($theirs)->payment;

        // 404 rather than 403: the tracking token is the whole credential on this surface, and
        // confirming a reference exists elsewhere is worth nothing to a customer and something
        // to anyone probing.
        $this->postJson("/api/v1/orders/{$mine->tracking_token}/payment/otp", [
            'reference' => $theirPayment->reference,
            'otp' => '123456',
        ])->assertStatus(404);
    }

    // ── Whose wallet is this ──────────────────────────────────────────────────

    public function test_a_resolved_wallet_comes_back_with_its_registered_name(): void
    {
        $this->withKeys();

        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'AMA SERWAA', 'account_number' => '0241234567'],
        ], 200)]);

        $this->postJson('/api/v1/momo/resolve', ['phone' => '0241234567'])
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.account_name', 'AMA SERWAA')
            // 024 is an MTN prefix, so the guess was tried first and it answered.
            ->assertJsonPath('data.network', 'mtn')
            ->assertJsonPath('data.matched_guess', true);
    }

    /**
     * ⚠️ THE CASE THE PREFIX TABLE CANNOT HANDLE, and the reason this endpoint exists at all.
     * Ghana has had number portability since 2011: an 024 number can sit on any network. The
     * guess is tried, it fails, and the network that actually answers is the network the
     * number lives on — detected rather than assumed.
     */
    public function test_a_ported_number_resolves_on_the_network_it_actually_lives_on(): void
    {
        $this->withKeys();

        Http::fake(function ($request) {
            $isAirtelTigo = str_contains($request->url(), 'bank_code=ATL');

            return Http::response([
                'status' => $isAirtelTigo,
                'data' => $isAirtelTigo ? ['account_name' => 'KOFI MENSAH'] : [],
                'message' => $isAirtelTigo ? 'ok' : 'Could not resolve account name',
            ], 200);
        });

        $this->postJson('/api/v1/momo/resolve', ['phone' => '0241234567'])
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.network', 'atl')
            ->assertJsonPath('data.account_name', 'KOFI MENSAH')
            ->assertJsonPath('data.matched_guess', false);
    }

    /**
     * ⚠️ LAW 7, ON THE ONE ENDPOINT MOST TEMPTING TO TREAT AS A GATE. A lookup that cannot be
     * evaluated must not stop anybody paying — it comes back `resolved: false` with a 200, the
     * checkout screen falls through to the network picker it has always had, and the worst
     * outcome is the experience the product had before this existed.
     */
    public function test_a_lookup_that_answers_nothing_is_not_an_error(): void
    {
        $this->withKeys();

        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => false,
            'message' => 'Could not resolve account name',
        ], 200)]);

        $this->postJson('/api/v1/momo/resolve', ['phone' => '0241234567'])
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.reason', 'no_match');
    }

    public function test_no_keys_means_unresolved_rather_than_broken(): void
    {
        // Deliberately no `withKeys()`. This is the state the system is in until credentials
        // are supplied, and it must not surface as a failure to the customer.
        Http::fake();

        $this->postJson('/api/v1/momo/resolve', ['phone' => '0241234567'])
            ->assertOk()
            ->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.reason', 'not_configured');

        Http::assertNothingSent();
    }
}
