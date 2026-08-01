<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CycleDay;
use App\Models\CycleDayItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderCycle;
use App\Models\Promo;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\Ordering\CycleBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Discount codes.
 *
 * ⚠️ THE FIRST TEST IN THIS FILE IS THE ONE THAT MATTERS.
 *
 * A discount that reaches the delivery fee does not cost her a smaller margin — it costs her
 * cash, because that fee is collected for a third-party courier and handed straight over
 * (brief §5.3). She pays the courier ₵15 either way. It is invisible on a receipt, because
 * the delivery line still reads ₵15, and it scales with every delivery the code touches.
 */
final class PromoTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

    private CycleDay $day;

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
            'orders_close_at' => '2026-08-14T18:00:00Z',
        ]);
        $this->cycle->update(['status' => CycleStatus::Published->value]);

        $this->day = $this->cycle->days()->orderBy('date')->firstOrFail();

        foreach (['waakye', 'plantain-etor'] as $slug) {
            $item = MenuItem::query()->where('slug', $slug)->firstOrFail();
            CycleDayItem::query()->updateOrCreate(
                ['cycle_day_id' => $this->day->id, 'menu_item_id' => $item->id],
                ['is_available' => true],
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    private function asStaff(User $user): static
    {
        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    private function option(string $slug): int
    {
        return (int) MenuItem::query()->where('slug', $slug)->firstOrFail()->options()->value('id');
    }

    private function promo(array $attributes = []): Promo
    {
        return Promo::query()->create(array_merge([
            'code' => 'SUMMER',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ], $attributes));
    }

    /** A basket through the real customer path: create, apply a code, read the quote. */
    private function basket(array $lines, ?string $code = null): array
    {
        $response = $this->postJson('/api/v1/checkout-sessions', [
            'lines' => $lines,
            'cycle_day_id' => $this->day->id,
            'promo_code' => $code,
        ])->assertCreated();

        return [
            'token' => $response->json('data.token'),
            'session' => $response->json('data.guest_session'),
            'body' => $response->json('data'),
        ];
    }

    private function mealLines(int $quantity = 2): array
    {
        return [['menu_item_option_id' => $this->option('waakye'), 'quantity' => $quantity]];
    }

    /** Place a real order by hand, which runs the same `OrderCreator` the storefront does. */
    private function place(array $overrides = []): array
    {
        return $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/orders', array_merge([
                'lines' => $this->mealLines(),
                'order_type' => 'pickup',
                'source' => 'whatsapp',
                'cycle_day_id' => $this->day->id,
                'contact_name' => 'Kwame Boateng',
                'contact_phone' => '0244000111',
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    // ── ⚠️ The one the milestone is for ───────────────────────────────────────

    /**
     * ⚠️ THE DISCOUNT NEVER TOUCHES THE COURIER'S FEE.
     *
     * 100% off, on a delivery. The food goes to zero; the delivery fee is untouched, and the
     * total is exactly the fee. If a discount could reach it, this order would total zero
     * and she would pay the courier out of her own pocket.
     */
    public function test_a_discount_never_reduces_the_pass_through_delivery_fee(): void
    {
        $this->promo(['code' => 'EVERYTHING', 'type' => 'percentage', 'value' => 100]);

        $order = $this->place([
            'order_type' => 'delivery',
            'delivery_address' => '14 Ring Road East',
            'delivery_area' => 'Osu',
            'promo_code' => 'EVERYTHING',
        ]);

        $row = Order::query()->findOrFail($order['id']);

        $this->assertGreaterThan(0, $row->delivery_fee, 'This test needs a delivery fee to protect.');
        $this->assertSame($row->subtotal, $row->discount, 'The whole subtotal should be discounted.');
        $this->assertSame($row->delivery_fee, $row->total, 'The fee, and nothing but the fee.');
    }

    // ── The arithmetic ────────────────────────────────────────────────────────

    public function test_a_percentage_comes_off_the_subtotal(): void
    {
        $this->promo(['value' => 25]);

        $order = Order::query()->findOrFail($this->place(['promo_code' => 'SUMMER'])['id']);

        $this->assertSame((int) round($order->subtotal * 0.25), $order->discount);
        $this->assertSame($order->subtotal - $order->discount, $order->total);
    }

    /** ₵50 off a ₵20 basket takes ₵20. A discount bigger than the thing is a refund. */
    public function test_a_fixed_discount_can_never_exceed_the_subtotal(): void
    {
        $this->promo(['code' => 'BIG', 'type' => 'fixed', 'value' => 5_000_00]);

        $order = Order::query()->findOrFail($this->place(['promo_code' => 'BIG'])['id']);

        $this->assertSame($order->subtotal, $order->discount);
        $this->assertSame(0, $order->total);
    }

    public function test_a_percentage_is_capped_by_max_discount(): void
    {
        $this->promo(['value' => 50, 'max_discount' => 500]);

        $order = Order::query()->findOrFail($this->place(['promo_code' => 'SUMMER'])['id']);

        $this->assertSame(500, $order->discount);
    }

    /**
     * ⚠️ SCOPE NARROWS THE DISCOUNTABLE SUBTOTAL — IT IS NOT A YES/NO ON THE ORDER.
     *
     * A pantry code on a basket holding one jar and two dinners takes its percentage off the
     * jar. Treating scope as a gate would take it off the whole basket, which is how a
     * launch code for a ₵45 product takes ₵90 off a catering order.
     */
    public function test_a_scoped_discount_only_counts_the_lines_it_covers(): void
    {
        $this->promo(['code' => 'JAR', 'scope' => 'pantry', 'type' => 'percentage', 'value' => 50]);

        $jar = MenuItem::query()->where('category', 'pantry')->firstOrFail();
        $jarOption = $jar->options()->firstOrFail();

        $order = Order::query()->findOrFail($this->place([
            'lines' => [
                ['menu_item_option_id' => $this->option('waakye'), 'quantity' => 2],
                ['menu_item_option_id' => $jarOption->id, 'quantity' => 1],
            ],
            'promo_code' => 'JAR',
        ])['id']);

        $this->assertSame(
            (int) round($jarOption->price * 0.5),
            $order->discount,
            'Half the jar, not half the basket.',
        );
    }

    // ── The refusals, each with its own reason ────────────────────────────────

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        return [
            'expired' => [['ends_at' => '2026-07-01T00:00:00Z'], 'expired'],
            'not yet started' => [['starts_at' => '2026-09-01T00:00:00Z'], 'not_yet_started'],
            'switched off' => [['is_active' => false], 'inactive'],
            'fully used' => [['usage_limit' => 1, 'times_used' => 1], 'exhausted'],
            'below the minimum' => [['min_subtotal' => 100_000_00], 'below_minimum'],
            'wrong scope' => [['scope' => 'pantry'], 'scope_mismatch'],
        ];
    }

    /**
     * ⚠️ A REASON, NEVER A BARE `false`. "That code isn't valid" is the message that
     * generates a phone call; "that code runs out on Friday" is one she never hears about.
     */
    #[DataProvider('refusals')]
    public function test_each_refusal_says_which_one_it_is(array $attributes, string $expected): void
    {
        $promo = $this->promo();
        // `times_used` is not fillable — it is a counter, not a field — so it is forced here
        // rather than passed to the factory.
        $promo->forceFill($attributes)->save();

        $basket = $this->basket($this->mealLines(), 'SUMMER');

        $quote = $this->withHeader('X-Guest-Session', $basket['session'])
            ->getJson("/api/v1/checkout-sessions/{$basket['token']}")
            ->assertOk();

        $this->assertSame('refused', $quote->json('data.promo.outcome'));
        $this->assertSame($expected, $quote->json('data.promo.reason'));
        $this->assertNotNull($quote->json('data.promo.message'));
    }

    /**
     * ⚠️ AN UNKNOWN CODE AND A DEACTIVATED ONE READ THE SAME TO A CUSTOMER.
     *
     * Distinguishing them turns the endpoint into an oracle for enumerating which codes
     * exist. The `reason` still differs, so we can tell them apart in a log.
     */
    public function test_an_unknown_code_is_indistinguishable_from_a_switched_off_one(): void
    {
        $this->promo(['is_active' => false]);

        $unknown = $this->quoteFor('NOPE-NOT-REAL');
        $inactive = $this->quoteFor('SUMMER');

        $this->assertSame($unknown['message'], $inactive['message']);
        $this->assertNotSame($unknown['reason'], $inactive['reason']);
    }

    private function quoteFor(string $code): array
    {
        $basket = $this->basket($this->mealLines(), $code);

        return $this->withHeader('X-Guest-Session', $basket['session'])
            ->getJson("/api/v1/checkout-sessions/{$basket['token']}")
            ->assertOk()
            ->json('data.promo');
    }

    // ── ⚠️ The quote is never trusted ─────────────────────────────────────────

    /**
     * ⚠️ THE DISCOUNT IS RE-RESOLVED AGAINST THE BASKET AT CONFIRM.
     *
     * Apply a code to a big basket, shrink the basket, then confirm. The discount that lands
     * on the order belongs to the small basket. A discount carried forward from a quote is
     * a number the client chose.
     */
    public function test_shrinking_the_basket_after_applying_a_code_reprices_the_discount(): void
    {
        $this->promo(['value' => 50]);

        $basket = $this->basket($this->mealLines(quantity: 10), 'SUMMER');

        $big = $this->withHeader('X-Guest-Session', $basket['session'])
            ->getJson("/api/v1/checkout-sessions/{$basket['token']}")
            ->json('data.promo.discount');

        $this->withHeader('X-Guest-Session', $basket['session'])
            ->patchJson("/api/v1/checkout-sessions/{$basket['token']}", ['lines' => $this->mealLines(quantity: 1)])
            ->assertOk();

        $confirmed = $this->withHeader('X-Guest-Session', $basket['session'])
            ->postJson("/api/v1/checkout-sessions/{$basket['token']}/confirm", [
                'order_type' => 'pickup',
                'contact_name' => 'Ama Serwaa',
                'contact_phone' => '0244000222',
            ])->assertCreated();

        $order = Order::query()->findOrFail($confirmed->json('data.id'));

        $this->assertLessThan($big, $order->discount, 'The quote was carried forward.');
        $this->assertSame((int) round($order->subtotal * 0.5), $order->discount);
    }

    // ── Limits ────────────────────────────────────────────────────────────────

    /**
     * ⚠️ COUNTED ON THE PHONE, NOT ON `customer_id`.
     *
     * Most orders are from guests with no customer row. A per-customer limit counted on a
     * null id is not a limit at all.
     */
    public function test_a_once_per_customer_code_refuses_the_same_number_twice(): void
    {
        $this->promo(['usage_limit_per_customer' => 1]);

        $first = Order::query()->findOrFail($this->place(['promo_code' => 'SUMMER'])['id']);
        $this->assertGreaterThan(0, $first->discount);

        $second = Order::query()->findOrFail($this->place(['promo_code' => 'SUMMER'])['id']);

        $this->assertSame(0, $second->discount, 'The same number used it twice.');
        $this->assertNull($second->promo_id);
        $this->assertSame('SUMMER', $second->promo_code, 'The attempt is still recorded.');
    }

    /** A different number is a different customer. */
    public function test_a_once_per_customer_code_still_works_for_somebody_else(): void
    {
        $this->promo(['usage_limit_per_customer' => 1]);

        $this->place(['promo_code' => 'SUMMER']);
        $other = Order::query()->findOrFail(
            $this->place(['promo_code' => 'SUMMER', 'contact_phone' => '0244000999'])['id'],
        );

        $this->assertGreaterThan(0, $other->discount);
    }

    /**
     * ⚠️ CANCELLED ORDERS COUNT TOWARDS "FIRST ORDER ONLY".
     *
     * Otherwise the rule is defeated by ordering, cancelling and ordering again — and a code
     * worth 30% is worth the two minutes that takes.
     */
    public function test_first_order_only_is_not_defeated_by_cancelling(): void
    {
        $this->promo(['first_order_only' => true, 'value' => 30]);

        $first = $this->place();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/orders/{$first['id']}/status", ['status' => 'cancelled'])
            ->assertOk();

        $second = Order::query()->findOrFail($this->place(['promo_code' => 'SUMMER'])['id']);

        $this->assertSame(0, $second->discount);
    }

    public function test_a_used_code_increments_its_counter_once_and_leaves_a_redemption(): void
    {
        $promo = $this->promo();

        $order = Order::query()->findOrFail($this->place(['promo_code' => 'SUMMER'])['id']);

        $this->assertSame(1, $promo->refresh()->times_used);

        $redemption = PromoRedemption::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame($order->discount, $redemption->discount);
        $this->assertSame($order->contact_phone, $redemption->phone);
    }

    /**
     * ⚠️ AN EXHAUSTED CODE DOES NOT KILL THE SALE.
     *
     * The order is placed at full price. Refusing a confirmed sale at the final step over a
     * promo is a far worse outcome than a customer paying what their basket is worth.
     */
    public function test_a_code_that_runs_out_does_not_refuse_the_order(): void
    {
        $this->promo(['usage_limit' => 1]);

        $this->place(['promo_code' => 'SUMMER']);
        $second = Order::query()->findOrFail(
            $this->place(['promo_code' => 'SUMMER', 'contact_phone' => '0244000888'])['id'],
        );

        $this->assertSame(0, $second->discount);
        $this->assertSame($second->subtotal, $second->total);
        $this->assertSame(1, Promo::query()->firstOrFail()->times_used, 'The counter moved twice.');
    }

    // ── The code itself ───────────────────────────────────────────────────────

    /** Stored uppercase and matched exactly, so the unique index is the guarantee. */
    public function test_a_code_is_matched_whatever_case_it_is_typed_in(): void
    {
        $this->promo();

        $order = Order::query()->findOrFail($this->place(['promo_code' => '  summer '])['id']);

        $this->assertGreaterThan(0, $order->discount);
        $this->assertSame('SUMMER', $order->promo_code);
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function test_creating_a_promo_normalises_its_code(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/promos', [
                'code' => 'launch10',
                'type' => 'percentage',
                'value' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'LAUNCH10');
    }

    public function test_a_percentage_over_a_hundred_is_refused(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/promos', [
                'code' => 'IMPOSSIBLE',
                'type' => 'percentage',
                'value' => 150,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    /** A cap on a fixed discount is meaningless, and the database refuses to store one. */
    public function test_a_cap_on_a_fixed_discount_is_refused(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/promos', [
                'code' => 'CAPPED',
                'type' => 'fixed',
                'value' => 1000,
                'max_discount' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_discount');
    }

    /**
     * ⚠️ A USED PROMO IS SWITCHED OFF, NEVER DELETED. `promo_redemptions` cascades, so
     * deleting the row would destroy the evidence behind every order that used it.
     */
    public function test_deleting_a_used_promo_deactivates_it_instead(): void
    {
        $promo = $this->promo();
        $this->place(['promo_code' => 'SUMMER']);

        $this->asStaff($this->admin())
            ->deleteJson("/api/v1/admin/promos/{$promo->id}")
            ->assertOk();

        $this->assertNotNull($promo->fresh(), 'The promo was deleted.');
        $this->assertFalse($promo->refresh()->is_active);
        $this->assertSame(1, PromoRedemption::query()->count());
    }

    public function test_an_unused_promo_is_deleted_outright(): void
    {
        $promo = $this->promo();

        $this->asStaff($this->admin())
            ->deleteJson("/api/v1/admin/promos/{$promo->id}")
            ->assertOk();

        $this->assertNull($promo->fresh());
    }

    /** Reading which codes run and minting one are different grants. */
    public function test_creating_a_promo_needs_more_than_permission_to_read_them(): void
    {
        SpatieRole::findByName(Role::Admin->value, 'web')
            ->revokePermissionTo(Permission::PromosManage->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->forgetAuth();

        $this->asStaff($this->admin())->getJson('/api/v1/admin/promos')->assertOk();
        $this->forgetAuth();

        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/promos', ['code' => 'X', 'type' => 'fixed', 'value' => 100])
            ->assertForbidden();
    }
}
