<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Contracts\SmsSender;
use App\Enums\CycleStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\User;
use App\Services\Ordering\BasketLine;
use App\Services\Ordering\CycleBuilder;
use App\Services\Ordering\OrderCreator;
use App\Services\Ordering\OrderDraft;
use App\Services\Ordering\OrderStatusMachine;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\OrderNotifier;
use App\Services\Sms\SmsOnlineGhSender;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMS.
 *
 * ⚠️ EVERY TEST IN THIS FILE RUNS ON THE `log` DRIVER. That is pinned in `phpunit.xml` AND
 * defaulted in `AppServiceProvider` whenever credentials are missing, because a test suite
 * that *can* reach a real gateway will eventually reach it — and the person who finds out is
 * a customer at 3am.
 *
 * The one place the real driver is exercised, `SmsOnlineGhSender`, is driven entirely
 * through `Http::fake()`.
 */
final class SmsTest extends TestCase
{
    use RefreshDatabase;

    private CycleDay $day;

    private MenuOption $waakye;

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

        $this->log()->flush();
    }

    private function log(): LogSmsSender
    {
        $sender = app(SmsSender::class);

        // If this ever fails, the binding has changed and the suite is one config away from
        // texting real people.
        $this->assertInstanceOf(LogSmsSender::class, $sender, 'Tests must run on the log driver.');

        return $sender;
    }

    private function order(OrderType $type = OrderType::Pickup, OrderSource $source = OrderSource::Online): Order
    {
        return app(OrderCreator::class)->create(new OrderDraft(
            lines: [new BasketLine($this->waakye->id, 2)],
            type: $type,
            source: $source,
            contactName: 'Ama Serwaa',
            contactPhone: '024 123 4567',
            cycleDayId: $this->day->id,
            deliveryAddress: $type === OrderType::Pickup ? null : '12 Ring Road, Accra',
            actor: $source === OrderSource::Online ? null : $this->staff(),
        ));
    }

    private function staff(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    /** @return list<string> */
    private function messages(): array
    {
        return array_map(fn (array $row) => $row['message'], $this->log()->sent());
    }

    // ── What gets sent, and when ──────────────────────────────────────────────

    public function test_placing_an_order_texts_the_customer_once(): void
    {
        $order = $this->order();

        $sent = $this->log()->sent();

        $this->assertCount(1, $sent);
        // ⚠️ E.164, always. The customer typed "024 123 4567"; the gateway gets the
        // normalised form, and the two are the same person.
        $this->assertSame('+233241234567', $sent[0]['to']);
        $this->assertStringContainsString($order->order_number, $sent[0]['message']);
        $this->assertStringContainsString('Collect', $sent[0]['message']);
    }

    public function test_a_confirmation_says_what_the_order_is_for(): void
    {
        $this->order();

        $message = $this->messages()[0];

        $this->assertStringContainsString('Wed 5 Aug', $message);
        $this->assertStringContainsString('GHS 80.00', $message);

        // One segment. She pays per 160 characters, and a message that quietly costs double
        // is a cost nobody reviews.
        $this->assertLessThanOrEqual(160, strlen($message), "Message runs to a second segment:\n{$message}");
    }

    /**
     * ⚠️ ONE HANDOVER TEXT, NOT TWO.
     *
     * `ready_for_pickup` can only be reached from `ready`, so texting on both would send the
     * customer the same news twice and cost her two segments to do it.
     */
    public function test_a_pickup_order_is_told_once_that_it_is_ready(): void
    {
        $order = $this->order(OrderType::Pickup);
        $this->log()->flush();

        $machine = app(OrderStatusMachine::class);
        $machine->transition($order, OrderStatus::Accepted, $this->staff());
        $machine->transition($order, OrderStatus::Preparing, $this->staff());
        $machine->transition($order, OrderStatus::Ready, $this->staff());
        $machine->transition($order, OrderStatus::ReadyForPickup, $this->staff());

        $sent = $this->messages();

        $this->assertCount(1, $sent, "Expected one handover text, got:\n".implode("\n", $sent));
        $this->assertStringContainsString('ready to collect', $sent[0]);
    }

    /**
     * "On its way" at `ready` would be a lie — the food is cooked and still on the counter.
     */
    public function test_a_delivery_order_is_told_when_it_leaves_not_when_it_is_cooked(): void
    {
        $order = $this->order(OrderType::Delivery);
        $this->log()->flush();

        $machine = app(OrderStatusMachine::class);
        $machine->transition($order, OrderStatus::Accepted, $this->staff());
        $machine->transition($order, OrderStatus::Preparing, $this->staff());
        $machine->transition($order, OrderStatus::Ready, $this->staff());

        $this->assertSame([], $this->messages(), 'Told the customer it was on its way while it sat on the counter.');

        $machine->transition($order, OrderStatus::OutForDelivery, $this->staff());

        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('on its way', $this->messages()[0]);
    }

    public function test_the_quiet_transitions_say_nothing(): void
    {
        $order = $this->order();
        $this->log()->flush();

        $machine = app(OrderStatusMachine::class);
        $machine->transition($order, OrderStatus::Accepted, $this->staff());
        $machine->transition($order, OrderStatus::Preparing, $this->staff());

        // She said yes, then she started cooking, on a day they already know about. A text
        // per transition trains people to ignore the one that matters.
        $this->assertSame([], $this->messages());
    }

    public function test_cancelling_tells_the_customer(): void
    {
        $order = $this->order();
        $this->log()->flush();

        app(OrderStatusMachine::class)->transition($order, OrderStatus::Cancelled, $this->staff());

        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('cancelled', $this->messages()[0]);
    }

    public function test_an_expired_hold_tells_the_customer_it_was_cancelled(): void
    {
        $this->order();
        $this->log()->flush();

        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:31:00Z'));
        $this->artisan('orders:release-expired-holds')->assertSuccessful();

        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('cancelled', $this->messages()[0]);
    }

    // ── The payment reminder is hers alone ────────────────────────────────────

    public function test_only_her_manual_orders_get_a_payment_reminder(): void
    {
        $online = $this->order(OrderType::Pickup, OrderSource::Online);
        $manual = $this->order(OrderType::Pickup, OrderSource::Phone);
        $this->log()->flush();

        $notifier = app(OrderNotifier::class);

        // The customer is mid-checkout looking at a payment screen. Texting them about it is
        // noise, and noise is what makes people ignore the message that matters.
        $notifier->paymentReminder($online);
        $this->assertSame([], $this->messages());

        $notifier->paymentReminder($manual);
        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString($manual->order_number, $this->messages()[0]);
    }

    public function test_a_paid_order_is_never_chased_for_payment(): void
    {
        $order = $this->order(OrderType::Pickup, OrderSource::Phone);
        $order->forceFill(['is_paid' => true])->save();
        $this->log()->flush();

        app(OrderNotifier::class)->paymentReminder($order);

        $this->assertSame([], $this->messages());
    }

    // ── The kill switch ───────────────────────────────────────────────────────

    public function test_disabling_sms_stops_everything_without_touching_the_driver(): void
    {
        config(['sms.enabled' => false]);

        $this->order();

        // "Stop texting people right now" must not require deciding which gateway you are
        // not using, and must not require a deploy.
        $this->assertSame([], $this->messages());
    }

    public function test_an_order_is_still_placed_when_sms_is_dead(): void
    {
        // Law 7: `unjudged` must not block a sale. A gateway nobody can reach is a
        // notification problem, and the order is already paid for.
        config(['sms.enabled' => false]);

        $order = $this->order();

        $this->assertSame(OrderStatus::Received, $order->status);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    // ── The real driver, faked at the wire ────────────────────────────────────

    private function gateway(): SmsOnlineGhSender
    {
        return new SmsOnlineGhSender('test-key', 'Mefs', 'https://api.smsonlinegh.com');
    }

    /**
     * The response body below is copied from a real HSHK_OK answered by the live account on
     * 2026-08-18, not composed from the documentation.
     */
    public function test_the_gateway_driver_reports_a_send(): void
    {
        Http::fake(['*' => Http::response([
            'handshake' => ['id' => 0, 'label' => 'HSHK_OK'],
            'data' => ['batch' => '43cba60160c739462c57777614c93eda'],
        ], 200)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        $this->assertTrue($result->wasSent());
        // ⚠️ `data.batch`. This read `data.messages[0].id` and would have been null forever —
        // survivable, invisible, and therefore permanent.
        $this->assertSame('43cba60160c739462c57777614c93eda', $result->reference);
    }

    /**
     * ⚠️ THE TEST THAT WAS MISSING, AND THE BUG IT WOULD HAVE CAUGHT.
     *
     * This driver posted `Http::asJson()` with a nested `messages[].destinations[].to` array
     * for its whole life. The `/v5/` API parses only form data, so the gateway could never
     * have read a single one of those requests — and the old version of the test above
     * asserted that exact nesting and passed, because a faked gateway agrees with whatever you
     * send it. Response-shaped tests cannot catch a request-shaped bug.
     *
     * So this asserts the wire: form-encoded, five flat parameters, credential in the body,
     * number with no `+`, and nothing nested anywhere.
     */
    public function test_the_request_is_form_encoded_and_flat_because_json_is_not_accepted(): void
    {
        Http::fake(['*' => Http::response(['handshake' => ['label' => 'HSHK_OK']], 200)]);

        $this->gateway()->send('+233241234567', 'hello');

        Http::assertSent(function ($request) {
            $this->assertStringContainsString(
                'application/x-www-form-urlencoded',
                $request->header('Content-Type')[0] ?? '',
                'The v5 API parses only form data. JSON is silently unreadable to it.',
            );

            // The credential is a body parameter, which is their canonical example. One
            // credential path, so an auth failure has exactly one place to be wrong.
            $this->assertSame('test-key', $request['key']);
            $this->assertSame('hello', $request['text']);
            $this->assertSame('Mefs', $request['sender']);
            $this->assertSame('0', (string) $request['type']);

            // E.164 in storage, no leading `+` on the wire — the one place the two
            // conventions meet.
            $this->assertSame('233241234567', $request['to']);

            // The shape that could never work, asserted absent rather than merely unused.
            $this->assertArrayNotHasKey('messages', $request->data());

            return true;
        });
    }

    /**
     * ⚠️ A 200 IS NOT ALWAYS AN ACCEPTANCE.
     *
     * Gateways in this class habitually answer 200 with a failure in the body. Reading the
     * status alone would report every rejected message as sent — the "plausible success"
     * failure this codebase keeps guarding against.
     */
    public function test_a_two_hundred_with_a_failure_in_the_body_is_not_a_send(): void
    {
        Http::fake(['*' => Http::response(['handshake' => ['label' => 'HSHK_INVALID_DESTINATION']], 200)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        $this->assertFalse($result->wasSent());
        $this->assertSame('refused', $result->status);
        $this->assertSame('HSHK_INVALID_DESTINATION', $result->reason);
    }

    /**
     * ⚠️ THE LOAD-BEARING SPLIT. A 4xx is us being wrong and retrying repeats the mistake; a
     * 5xx is them, and retrying is the correct response. Backwards, and either a broken
     * gateway looks like a thousand bad numbers or one bad number retries forever.
     */
    public function test_a_client_error_is_refused_and_never_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid destination'], 422)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        $this->assertSame('refused', $result->status);
        $this->assertSame('Invalid destination', $result->reason);
        $this->assertFalse($result->isRetryable());
    }

    /** Its own test: a second `Http::fake()` inside one test does not replace the first. */
    public function test_a_server_error_is_retryable(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        $this->assertSame('unavailable', $result->status);
        $this->assertTrue($result->isRetryable());
    }

    public function test_bad_credentials_are_unavailable_rather_than_refused(): void
    {
        Http::fake(['*' => Http::response([], 401)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        // Nothing is wrong with the MESSAGE. It should go out once the key is fixed, not be
        // marked permanently undeliverable.
        $this->assertSame('unavailable', $result->status);
        $this->assertTrue($result->isRetryable());
    }

    /**
     * ⚠️ THE 401 BRANCH IS DEAD CODE ON THIS GATEWAY, AND THIS IS THE CASE THAT ACTUALLY HAPPENS.
     *
     * An auth failure comes back HTTP **200** with the verdict in the body. The response below
     * is copied verbatim from a deliberately revoked key on 2026-08-18, not composed.
     *
     * Before the fix this fell through to the generic handshake check and returned `refused`,
     * which `SendSms` does not retry — so every queued order confirmation and every login code
     * in flight during a key rotation would have been discarded, silently, for a reason that
     * has nothing to do with the message.
     */
    public function test_an_auth_failure_arrives_as_a_two_hundred_and_is_still_retryable(): void
    {
        Http::fake(['*' => Http::response([
            'handshake' => ['id' => 1203, 'label' => 'HSHK_ERR_UA_AUTH'],
            'data' => null,
        ], 200)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        $this->assertSame('unavailable', $result->status);
        $this->assertSame('rejected_credentials', $result->reason);
        $this->assertTrue($result->isRetryable(), 'A rotated key must not destroy the queue.');
    }

    /**
     * The counterpart, so the fix above cannot quietly become "retry every handshake failure".
     * A message-level rejection is permanent: waiting does not conjure up a destination that
     * does not exist, and retrying forever burns segments she pays for.
     */
    public function test_a_message_level_handshake_failure_is_still_refused(): void
    {
        Http::fake(['*' => Http::response([
            'handshake' => ['id' => 0, 'label' => 'HSHK_ERR_MSG_DESTINATION'],
        ], 200)]);

        $result = $this->gateway()->send('+233241234567', 'hello');

        $this->assertSame('refused', $result->status);
        $this->assertFalse($result->isRetryable());
    }

    public function test_a_driver_with_no_key_never_reaches_the_network(): void
    {
        Http::fake();

        $result = (new SmsOnlineGhSender('', 'Mefs', 'https://api.smsonlinegh.com'))
            ->send('+233241234567', 'hello');

        $this->assertSame('unavailable', $result->status);
        $this->assertSame('no_api_key', $result->reason);

        Http::assertNothingSent();
    }

    public function test_the_binding_falls_back_to_the_log_driver_without_a_key(): void
    {
        // ⚠️ THE DIRECTION MATTERS. A half-configured environment must text NOBODY rather
        // than everybody.
        config(['sms.driver' => 'smsonlinegh', 'sms.smsonlinegh.key' => '']);
        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(LogSmsSender::class, app(SmsSender::class));
    }
}
