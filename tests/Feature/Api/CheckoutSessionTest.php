<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\CycleStatus;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\MenuOption;
use App\Models\Order;
use App\Models\OrderCycle;
use App\Services\Ordering\CycleBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer's route to an order, over HTTP.
 *
 * The service-level rules are asserted in `OrderCreatorTest`; this file is about the seam —
 * that the endpoint reaches the same service, that the refusal survives the envelope with
 * its reason intact, and that a basket belongs to whoever holds both halves of its
 * credential.
 */
final class CheckoutSessionTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

    private CycleDay $day;

    private MenuOption $waakye;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));

        $this->cycle = app(CycleBuilder::class)->create([
            'name' => 'Week of 5 Aug',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => '2026-08-01T00:00:00Z',
            'orders_close_at' => '2026-08-04T18:00:00Z',
        ]);
        $this->cycle->update(['status' => CycleStatus::Published->value]);

        $this->day = $this->cycle->days()->orderBy('date')->firstOrFail();

        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        CycleDayItem::query()->updateOrCreate(
            ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
            ['is_available' => true],
        );

        $this->waakye = $item->options()->firstOrFail();
    }

    /** @return array{0: string, 1: string} token and guest session */
    private function openBasket(int $quantity = 2, bool $withDay = true): array
    {
        $response = $this->postJson('/api/v1/checkout-sessions', [
            'lines' => [['menu_item_option_id' => $this->waakye->id, 'quantity' => $quantity]],
            'cycle_day_id' => $withDay ? $this->day->id : null,
        ])->assertCreated();

        return [
            $response->json('data.token'),
            $response->json('data.guest_session'),
        ];
    }

    // ── The basket ────────────────────────────────────────────────────────────

    public function test_a_basket_is_priced_by_the_server_and_quoted_per_order_type(): void
    {
        $response = $this->postJson('/api/v1/checkout-sessions', [
            'lines' => [['menu_item_option_id' => $this->waakye->id, 'quantity' => 2]],
            'cycle_day_id' => $this->day->id,
        ])->assertCreated();

        // Nothing was sent about price. Everything about price comes back.
        $this->assertSame(4000, $response->json('data.lines.0.unit_price'));
        $this->assertSame(8000, $response->json('data.lines.0.line_total'));

        $quotes = collect($response->json('data.quotes'))->keyBy('order_type');

        $this->assertSame(8000, $quotes['pickup']['total']);
        $this->assertSame(10000, $quotes['delivery']['total'], 'Delivery adds the ₵20 fee.');

        // Not omitted — refused, with a sentence the checkout screen can show.
        $this->assertFalse($quotes['shipping']['available']);
        $this->assertNotEmpty($quotes['shipping']['unavailable_reason']);

        // The gate's verdict travels with the basket, reason and all.
        $this->assertSame('open', $response->json('data.ordering.status'));
        $this->assertSame('within_window', $response->json('data.ordering.reason'));
    }

    public function test_a_guest_session_is_minted_when_the_client_brings_none(): void
    {
        [, $guest] = $this->openBasket();

        $this->assertIsString($guest);
        $this->assertNotSame('', $guest);
    }

    public function test_a_basket_needs_both_halves_of_its_credential(): void
    {
        [$token, $guest] = $this->openBasket();

        $this->withHeader('X-Guest-Session', $guest)
            ->getJson("/api/v1/checkout-sessions/{$token}")
            ->assertOk();

        // ⚠️ 404, not 403. A 403 would confirm the token is real, and on an
        // unauthenticated surface the token is the whole credential.
        $this->withHeader('X-Guest-Session', 'somebody-elses-session')
            ->getJson("/api/v1/checkout-sessions/{$token}")
            ->assertNotFound();

        $this->getJson("/api/v1/checkout-sessions/{$token}")->assertNotFound();
    }

    public function test_a_basket_can_be_edited_until_it_is_confirmed(): void
    {
        [$token, $guest] = $this->openBasket(1);

        $this->withHeader('X-Guest-Session', $guest)
            ->patchJson("/api/v1/checkout-sessions/{$token}", [
                'lines' => [['menu_item_option_id' => $this->waakye->id, 'quantity' => 5]],
            ])
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', 5)
            ->assertJsonPath('data.quotes.0.total', 20000);
    }

    // ── Confirm ───────────────────────────────────────────────────────────────

    public function test_confirming_a_basket_creates_the_order_and_closes_the_basket(): void
    {
        [$token, $guest] = $this->openBasket();

        $response = $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '024 123 4567',
            ])
            ->assertCreated();

        $this->assertSame('A001', $response->json('data.order_number'));
        $this->assertSame(8000, $response->json('data.total'));
        $this->assertSame('2026-08-05', $response->json('data.fulfil_date'));
        $this->assertSame('received', $response->json('data.status'));

        // ⚠️ Staff-only fields must not be on a customer payload.
        $this->assertArrayNotHasKey('internal_notes', $response->json('data'));
        $this->assertArrayNotHasKey('slot_hold_expires_at', $response->json('data'));
        $this->assertArrayNotHasKey('actor_name', $response->json('data.status_history.0'));

        // A basket is spent once it has become an order.
        $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0241234567',
            ])
            ->assertStatus(409);

        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The refusal has to survive the envelope with its machine-readable reason, or the
     * storefront cannot tell "pick another day" from "pick another dish".
     */
    public function test_a_refused_confirm_returns_the_gates_reason_not_just_a_sentence(): void
    {
        // An hour before the cutoff, so the basket is still live when the window shuts.
        // A basket that had also expired would return 409 first and prove nothing about
        // the gate.
        $this->travelTo(CarbonImmutable::parse('2026-08-04T17:00:00Z'));

        [$token, $guest] = $this->openBasket();

        $this->travelTo(CarbonImmutable::parse('2026-08-04T18:00:01Z'));

        $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0241234567',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.refusal.reason', 'cutoff_passed')
            ->assertJsonPath('errors.refusal.ordering.status', 'closed');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_customer_cannot_claim_their_order_was_entered_by_hand(): void
    {
        [$token, $guest] = $this->openBasket();

        $response = $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0241234567',
                // Ignored — the source is not the body's to name. A manual order is the one
                // that may hold a slot unpaid for two hours (departure #6).
                'source' => 'phone',
                'is_manual_entry' => true,
                'total' => 1,
            ])
            ->assertCreated();

        $this->assertSame('online', $response->json('data.source'));
        $this->assertFalse($response->json('data.is_manual_entry'));
        $this->assertSame(8000, $response->json('data.total'));
    }

    public function test_an_abandoned_basket_expires_rather_than_waiting_forever(): void
    {
        [$token, $guest] = $this->openBasket();

        $this->travelTo(CarbonImmutable::parse('2026-08-03T10:00:01Z'));

        // Readable — she may still want to see what was in it — but not confirmable.
        $this->withHeader('X-Guest-Session', $guest)
            ->getJson("/api/v1/checkout-sessions/{$token}")
            ->assertOk();

        $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0241234567',
            ])
            ->assertStatus(409);
    }

    public function test_a_bad_phone_number_is_a_validation_error(): void
    {
        [$token, $guest] = $this->openBasket();

        $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '12345',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['contact_phone']]);
    }

    // ── Tracking ──────────────────────────────────────────────────────────────

    public function test_an_order_is_tracked_by_token_and_never_by_number(): void
    {
        [$token, $guest] = $this->openBasket();

        $order = $this->withHeader('X-Guest-Session', $guest)
            ->postJson("/api/v1/checkout-sessions/{$token}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0241234567',
            ])->json('data');

        // No credential at all — this is where the payment gateway sends someone.
        $this->getJson("/api/v1/orders/{$order['tracking_token']}")
            ->assertOk()
            ->assertJsonPath('data.order_number', 'A001')
            ->assertJsonPath('data.status', 'received');

        // The human-readable number is an identifier, not a credential, and the route it
        // is NOT keyed on is the point (brief §5.6).
        $this->getJson('/api/v1/orders/A001')->assertNotFound();
    }
}
