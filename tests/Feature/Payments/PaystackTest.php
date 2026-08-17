<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\CycleStatus;
use App\Enums\MomoNetwork;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\Payment;
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
 * Paystack, driven entirely through `Http::fake()`.
 *
 * ⚠️ NO KEY HAS EVER BEEN SUPPLIED, so nothing here has met the live gateway. What these
 * tests do prove is everything that is ours rather than theirs: the signature check, the
 * idempotency, the amount check, that a prompt goes to the wallet the customer named rather
 * than the number the kitchen rings, and — the one that matters most today — that an
 * unconfigured gateway does not stop a single order from being placed.
 */
final class PaystackTest extends TestCase
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

    /** Pretend a key has been supplied. */
    private function withKeys(): void
    {
        config(['paystack.secret_key' => self::SECRET]);
        $this->app->forgetInstance(PaystackClient::class);
    }

    private function order(): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, 2)],   // 8000 pesewas
            type: OrderType::Pickup,
            source: OrderSource::Online,
            contactName: 'Ama Serwaa',
            contactPhone: '0241234567',
            cycleDayId: $this->day->id,
        ));
    }

    /**
     * What Paystack answers a Ghana mobile money charge with: accepted, not paid.
     *
     * ⚠️ `pay_offline` IS THE HAPPY PATH AND IT MEANS NOTHING HAS BEEN PAID. The handset is
     * about to buzz; the money moves later, by webhook, or never.
     */
    private function fakeCharge(string $status = 'pay_offline'): void
    {
        Http::fake(['*/charge' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'set-by-us',
                'status' => $status,
                'display_text' => 'Please approve the transaction on your phone',
            ],
        ], 200)]);
    }

    /** Begin an attempt on the MTN number the fixtures use. */
    private function begin(Order $order, string $phone = '0241234567', ?string $network = null)
    {
        return app(PaymentInitiator::class)->begin($order, MomoInstruction::from($phone, $network));
    }

    /** @param array<string, mixed> $data */
    private function webhook(string $event, array $data, ?string $signature = null)
    {
        $body = json_encode(['event' => $event, 'data' => $data], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/api/v1/webhooks/paystack',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, self::SECRET),
            ],
            content: $body,
        );
    }

    // ── Without keys, which is where we are today ─────────────────────────────

    /**
     * ⚠️ THE TEST THAT MATTERS RIGHT NOW.
     *
     * No keys have been supplied. Law 7 says a check that cannot be evaluated must not block
     * a sale, and here that is not a hypothetical: every order placed between now and the
     * keys arriving depends on this being true.
     */
    public function test_an_order_is_placed_normally_with_no_paystack_keys(): void
    {
        Http::fake();

        $order = $this->order();

        $this->assertNotNull($order->id);
        $this->assertFalse($order->is_paid);
        $this->assertSame('pending', $order->payment_status->value);

        // No attempt row, and no request anywhere near the network.
        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_confirming_a_basket_without_keys_returns_a_null_payment(): void
    {
        Http::fake();

        $response = $this->placeOrderOverHttp();

        $response->assertCreated();
        $this->assertSame('A001', $response->json('data.order_number'));

        // ⚠️ Present and null, not absent. The storefront branches on `payment === null` to
        // show "she'll be in touch about payment"; a missing key would read as undefined and
        // be indistinguishable from a serialisation bug.
        $this->assertArrayHasKey('payment', $response->json('data'));
        $this->assertNull($response->json('data.payment'));
    }

    // ── The prompt ────────────────────────────────────────────────────────────

    public function test_confirming_a_basket_pushes_a_prompt_and_returns_no_url(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $response = $this->placeOrderOverHttp()->assertCreated();

        $this->assertSame(8000, $response->json('data.payment.amount'));
        $this->assertSame('+233241234567', $response->json('data.payment.momo_phone'));
        $this->assertSame('mtn', $response->json('data.payment.momo_network'));
        $this->assertSame('MTN MoMo', $response->json('data.payment.network_label'));

        // ⚠️ THE WHOLE POINT OF THE CHANGE. There is nowhere to send the customer, because
        // they are not going anywhere.
        $this->assertArrayNotHasKey('authorization_url', $response->json('data.payment'));

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status->value);
        $this->assertSame(8000, $payment->amount);
        $this->assertStringStartsWith('mefs_A001_', $payment->reference);
        $this->assertTrue($payment->isAwaitingPrompt());

        // ⚠️ The amount comes from the ORDER, in pesewas, with no conversion anywhere, and
        // the wallet and network go up in the shape Paystack expects.
        Http::assertSent(fn ($request) => $request['amount'] === 8000
            && $request['currency'] === 'GHS'
            && $request['mobile_money']['phone'] === '+233241234567'
            && $request['mobile_money']['provider'] === 'mtn');
    }

    /**
     * ⚠️ THE REDIRECT IS GONE, AND THIS IS THE TEST THAT KEEPS IT GONE.
     *
     * `PaystackClient` has no `initialize()` at all, so a hosted checkout cannot come back by
     * accident. If someone re-adds one, this fails before a customer is ever bounced to
     * another origin holding an unpaid order.
     */
    public function test_nothing_ever_calls_transaction_initialize(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $this->placeOrderOverHttp()->assertCreated();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'transaction/initialize'));
        $this->assertFalse(method_exists(PaystackClient::class, 'initialize'));
    }

    /**
     * ⚠️ THE PROMPT GOES TO THE WALLET, NOT TO THE CONTACT NUMBER.
     *
     * Someone orders for their mother: the kitchen rings the mother, the daughter pays. If
     * these two ever share a field, the daughter's phone gets the collection text and the
     * mother's wallet gets debited — which is both bugs at once.
     */
    public function test_the_prompt_goes_to_the_paying_wallet_not_the_contact_number(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $response = $this->placeOrderOverHttp(momoPhone: '0201112222')->assertCreated();

        // The kitchen still rings the number on the order…
        $this->assertSame('+233241234567', $response->json('data.contact_phone'));
        // …and Telecel debits the one that is paying.
        $this->assertSame('+233201112222', $response->json('data.payment.momo_phone'));
        $this->assertSame('vod', $response->json('data.payment.momo_network'));

        Http::assertSent(fn ($request) => $request['mobile_money']['phone'] === '+233201112222'
            && $request['mobile_money']['provider'] === 'vod');
    }

    /**
     * The network is inferred from the prefix when the customer does not say, and taken from
     * them when they do — because Ghana has number portability and they know, while we are
     * guessing. See `MomoNetwork::forPhone()`.
     */
    public function test_an_explicit_network_beats_the_one_inferred_from_the_prefix(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        // An 024 was issued by MTN, but this customer ported it to AirtelTigo.
        $this->placeOrderOverHttp(momoPhone: '0241234567', momoNetwork: 'atl')->assertCreated();

        Http::assertSent(fn ($request) => $request['mobile_money']['provider'] === 'atl');
        $this->assertSame(MomoNetwork::AirtelTigo, Payment::query()->firstOrFail()->momo_network);
    }

    /**
     * ⚠️ A MISSING NUMBER COSTS THE PROMPT, NEVER THE ORDER.
     *
     * A 422 here would throw away a placed order — gate run, slot taken — over a payment
     * detail. The order stands, `payment` is null, and the tracking page asks for a number.
     */
    public function test_an_order_without_a_momo_number_is_still_placed(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $response = $this->placeOrderOverHttp(momoPhone: null)->assertCreated();

        $this->assertSame('A001', $response->json('data.order_number'));
        $this->assertNull($response->json('data.payment'));

        // No attempt row and no charge: there was nowhere to send a prompt.
        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_gateway_outage_still_leaves_a_usable_order(): void
    {
        $this->withKeys();
        Http::fake(['*' => Http::response([], 503)]);

        $response = $this->placeOrderOverHttp()->assertCreated();

        $this->assertNull($response->json('data.payment'));
        $this->assertSame('A001', $response->json('data.order_number'));

        // The attempt row survives, marked pending. "We tried and could not" is a fact worth
        // keeping — deleting it makes "we tried three times" unknowable. But the prompt clock
        // comes off, because no phone is ringing.
        $payment = Payment::query()->firstOrFail();
        $this->assertSame('pending', $payment->status->value);
        $this->assertNull($payment->prompt_expires_at);
        $this->assertFalse($payment->isAwaitingPrompt());
    }

    /** A charge Paystack refuses outright is a failed attempt, not a pending one. */
    public function test_a_refused_charge_marks_the_attempt_failed(): void
    {
        $this->withKeys();
        Http::fake(['*/charge' => Http::response([
            'status' => false,
            'message' => 'Invalid phone number for the selected provider',
        ], 400)]);

        $response = $this->placeOrderOverHttp()->assertCreated();

        $this->assertNull($response->json('data.payment'));

        $payment = Payment::query()->firstOrFail();
        $this->assertSame('failed', $payment->status->value);
        $this->assertFalse($payment->isAwaitingPrompt());
    }

    // ── The webhook ───────────────────────────────────────────────────────────

    public function test_a_signed_charge_success_marks_the_order_paid(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        $this->webhook('charge.success', [
            'reference' => $payment->reference,
            'amount' => 8000,
            'channel' => 'mobile_money',
            'fees' => 120,
            'paid_at' => '2026-08-02T10:05:00Z',
        ])->assertOk();

        $order->refresh();

        $this->assertTrue($order->is_paid);
        $this->assertSame('completed', $order->payment_status->value);
        $this->assertSame('mobile_money', $order->payment_method);

        // ⚠️ A paid order is off the clock. The hold-expiry job must never sweep it.
        $this->assertNull($order->slot_hold_expires_at);

        $payment->refresh();
        $this->assertSame('completed', $payment->status->value);
        $this->assertSame(120, $payment->fee, 'Gross is not revenue; the fee is what makes that visible.');
        $this->assertNotNull($payment->payload, 'The raw callback is kept whole — a dispute is unarguable without it.');
    }

    /**
     * ⚠️ PAYSTACK RETRIES. ASSUME DUPLICATES.
     *
     * The unique index stops a replay creating a second attempt row; the row lock and status
     * check in `PaymentRecorder` stop it re-applying the same one. Neither is an `if` in a
     * controller that two concurrent workers could both pass (§5.7).
     */
    public function test_the_same_webhook_delivered_twice_is_a_no_op(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        $data = ['reference' => $payment->reference, 'amount' => 8000, 'channel' => 'mobile_money'];

        $this->webhook('charge.success', $data)->assertOk();
        $second = $this->webhook('charge.success', $data)->assertOk();

        $this->assertSame('ignored', $second->json('data.outcome'));
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Payment::query()->where('status', 'completed')->count());
    }

    /**
     * ⚠️ A SIGNATURE PROVES THE MESSAGE CAME FROM PAYSTACK. IT DOES NOT PROVE THE MESSAGE IS
     * ABOUT THE MONEY WE ASKED FOR.
     */
    public function test_a_correctly_signed_callback_for_the_wrong_amount_does_not_mark_it_paid(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        $response = $this->webhook('charge.success', [
            'reference' => $payment->reference,
            'amount' => 500,   // ₵5.00 against an order for ₵80.00
        ])->assertOk();

        $this->assertSame('mismatch', $response->json('data.outcome'));
        $this->assertFalse($order->fresh()->is_paid);
        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    public function test_an_unsigned_or_wrongly_signed_webhook_is_refused(): void
    {
        $this->withKeys();

        $this->webhook('charge.success', ['reference' => 'anything', 'amount' => 8000], 'not-the-signature')
            ->assertStatus(401);

        $this->webhook('charge.success', ['reference' => 'anything', 'amount' => 8000], '')
            ->assertStatus(401);
    }

    /**
     * A forged callback is the whole threat model: without verification, anyone who can
     * guess a reference marks any order paid.
     */
    public function test_a_forged_callback_cannot_mark_an_order_paid(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        $this->webhook(
            'charge.success',
            ['reference' => $payment->reference, 'amount' => 8000],
            hash_hmac('sha512', 'some other body', 'the-wrong-secret'),
        )->assertStatus(401);

        $this->assertFalse($order->fresh()->is_paid);
    }

    public function test_an_unknown_reference_is_acknowledged_rather_than_retried(): void
    {
        $this->withKeys();

        // 200 on purpose: a non-2xx makes Paystack retry, and retrying will not teach us
        // about a reference we have never had.
        $this->webhook('charge.success', ['reference' => 'mefs_A999_nothing', 'amount' => 100])
            ->assertOk();
    }

    public function test_a_failed_charge_leaves_the_order_pending_not_failed(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        $this->webhook('charge.failed', ['reference' => $payment->reference, 'amount' => 8000])->assertOk();

        // One attempt failing says nothing about the order — the customer can send another
        // prompt in the next thirty seconds.
        $this->assertSame('failed', $payment->fresh()->status->value);
        $this->assertSame('pending', $order->fresh()->payment_status->value);
        $this->assertFalse($order->fresh()->is_paid);
    }

    public function test_a_late_failure_never_downgrades_a_completed_payment(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        $this->webhook('charge.success', ['reference' => $payment->reference, 'amount' => 8000])->assertOk();
        $this->webhook('charge.failed', ['reference' => $payment->reference, 'amount' => 8000])->assertOk();

        $this->assertSame('completed', $payment->fresh()->status->value);
        $this->assertTrue($order->fresh()->is_paid);
    }

    public function test_the_webhook_refuses_everything_when_no_key_is_configured(): void
    {
        // Nothing can be verified, so nothing can be trusted. An endpoint that accepted
        // callbacks while unconfigured would be an unauthenticated write to `payments`.
        $this->webhook('charge.success', ['reference' => 'x', 'amount' => 1], 'anything')
            ->assertStatus(503);
    }

    // ── Finding out whether the prompt was approved ───────────────────────────

    /**
     * ⚠️ THE CUSTOMER'S BROWSER CANNOT SEE THE APPROVAL. It happens on the handset, off our
     * wire entirely, so the answer comes from a server-to-server call with our secret key.
     */
    public function test_verify_asks_paystack_rather_than_trusting_the_screen(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        Http::fake(['*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => $payment->reference, 'amount' => 8000, 'channel' => 'mobile_money'],
        ], 200)]);

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/verify")
            ->assertOk()
            ->assertJsonPath('data.paid', true);

        $this->assertTrue($order->fresh()->is_paid);
    }

    /**
     * ⚠️ "ABANDONED" WHILE THE PHONE IS STILL RINGING IS NOT A FAILURE.
     *
     * A charge Paystack has not seen answered can read as abandoned in the window before the
     * customer taps approve. Recording that would tell them their payment failed while the
     * prompt is still on their screen — and the retry they then start could collide with the
     * approval they were halfway through.
     */
    public function test_an_unanswered_prompt_is_still_waiting_not_failed(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        Http::fake(['*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'abandoned'],
        ], 200)]);

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/verify")
            ->assertOk()
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('data.attempt.awaiting_prompt', true)
            ->assertJsonPath('data.attempt.status', 'pending');

        $this->assertSame('pending', $payment->fresh()->status->value);
    }

    /** Once the window has run out, the same answer does close the attempt. */
    public function test_an_expired_prompt_reported_abandoned_is_closed(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();
        $this->begin($order);
        $payment = Payment::query()->firstOrFail();

        // Past the prompt window.
        $this->travel(10)->minutes();

        Http::fake(['*/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'abandoned'],
        ], 200)]);

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment/verify")
            ->assertOk()
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('data.attempt.awaiting_prompt', false);

        $this->assertSame('abandoned', $payment->fresh()->status->value);

        // ⚠️ The ORDER is untouched. `abandoned` describes an attempt and never an order —
        // she can still ring the customer, and they can still send another prompt.
        $this->assertSame('pending', $order->fresh()->payment_status->value);
    }

    public function test_a_customer_can_send_another_prompt_to_a_different_wallet(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment", [
            'momo_phone' => '0241234567',
        ])->assertCreated()->assertJsonPath('data.payment.momo_network', 'mtn');

        // Empty wallet, so they try another one.
        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment", [
            'momo_phone' => '0501112222',
        ])->assertCreated()->assertJsonPath('data.payment.momo_network', 'vod');

        // One row per ATTEMPT. Overwriting the first would make a settlement line naming the
        // earlier reference unexplainable at month end — and lose the fact that two different
        // numbers were tried, which is the first thing anyone asks.
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(2, Payment::query()->distinct()->count('reference'));
        $this->assertSame(
            ['+233241234567', '+233501112222'],
            Payment::query()->orderBy('id')->pluck('momo_phone')->all(),
        );
    }

    /**
     * Asking to pay with no number is a request for a number, not a gateway outage — and the
     * two get different sentences, because one sends the customer to the phone for nothing.
     */
    public function test_starting_a_payment_with_no_number_asks_for_one(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment")
            ->assertOk()
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.reason', 'momo_number_missing')
            ->assertJsonPath('message', 'We need a mobile money number to send the prompt to.');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_bad_momo_number_is_refused_before_it_reaches_paystack(): void
    {
        $this->withKeys();
        $this->fakeCharge();

        $order = $this->order();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment", [
            'momo_phone' => '12345',
        ])->assertStatus(422)->assertJsonValidationErrors('momo_phone');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/charge'));
    }

    public function test_an_already_paid_order_cannot_be_paid_again(): void
    {
        $this->withKeys();
        Http::fake();

        $order = $this->order();
        $order->forceFill(['is_paid' => true, 'payment_status' => 'completed'])->save();

        $this->postJson("/api/v1/orders/{$order->tracking_token}/payment", [
            'momo_phone' => '0241234567',
        ])->assertOk()->assertJsonPath('data.payment', null);

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_payment_routes_are_keyed_on_the_tracking_token(): void
    {
        $this->withKeys();
        Http::fake();

        $this->order();

        // The order number is an identifier, not a credential (§5.6).
        $this->postJson('/api/v1/orders/A001/payment')->assertNotFound();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function placeOrderOverHttp(
        ?string $momoPhone = '0241234567',
        ?string $momoNetwork = null,
    ) {
        $session = $this->postJson('/api/v1/checkout-sessions', [
            'lines' => [['menu_item_option_id' => $this->waakye->id, 'quantity' => 2]],
            'cycle_day_id' => $this->day->id,
        ])->assertCreated();

        return $this->withHeader('X-Guest-Session', $session->json('data.guest_session'))
            ->postJson("/api/v1/checkout-sessions/{$session->json('data.token')}/confirm", array_filter([
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0241234567',
                'momo_phone' => $momoPhone,
                'momo_network' => $momoNetwork,
            ], fn ($value) => $value !== null));
    }
}
