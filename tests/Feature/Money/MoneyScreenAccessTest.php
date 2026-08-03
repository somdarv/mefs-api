<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two requests the Money screens make on load, from the account that actually makes them.
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────────
 *
 * Both of these shipped broken to production and neither was a 500, so nothing was logged
 * and nothing looked wrong from the server side. The back office rendered "Couldn't load
 * payments. Check the API is running." and "Couldn't read the payment mode." — two sentences
 * that blame the infrastructure for a 422 and a 403 the API was issuing on purpose.
 *
 * The suite had plenty of coverage for both endpoints. What it did not have was a request
 * shaped the way the browser actually sends one, made by the role that actually signs in:
 *
 *   1. `unsettled=true` as a QUERY STRING, not a JSON boolean. Existing tests hit
 *      `/admin/payments` bare, which passes whatever the filter rule says.
 *   2. `payment-mode` as `admin` rather than `tech_admin`. Existing tests used the owner,
 *      who holds every permission and can therefore never catch a missing one.
 *
 * Both gaps have the same shape — testing the endpoint rather than the call — which is the
 * shape brief §5.8 warns about, where the original gated the endpoint the till didn't use.
 */
final class MoneyScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));
    }

    /** She runs the kitchen. Holds the same list as the owner — see `Permission::byRole()`. */
    private function kitchen(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    /** The owner account. Same grants; kept distinct because the role ceiling still differs. */
    private function owner(): User
    {
        return User::query()->where('email', 'owner@mefs.local')->firstOrFail();
    }

    private function asStaff(User $user): static
    {
        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    private function payment(string $reference, PaymentStatus $status, ?int $settled): Payment
    {
        return Payment::query()->create([
            'order_id' => Order::factory()->create()->id,
            'provider' => 'paystack',
            'reference' => $reference,
            'amount' => 12000,
            'currency' => 'GHS',
            'status' => $status->value,
            'settled_amount' => $settled,
        ]);
    }

    /* ── The payments list ─────────────────────────────────────────────────── */

    /**
     * ⚠️ THE FILTER ARRIVES AS THE STRING "true", BECAUSE THAT IS WHAT A URL CARRIES.
     *
     * Laravel's `boolean` validation rule accepts true, false, 1, 0, "1" and "0" — and not
     * "true". The screen opens on this filter, so the rule made its default view a 422.
     */
    public function test_the_unsettled_filter_works_as_a_query_string(): void
    {
        $this->payment('ref_owed', PaymentStatus::Completed, null);
        $this->payment('ref_settled', PaymentStatus::Completed, 12000);
        $this->payment('ref_failed', PaymentStatus::Failed, null);

        $response = $this->asStaff($this->kitchen())
            ->getJson('/api/v1/admin/payments?unsettled=true')
            ->assertOk();

        $references = array_column($response->json('data.payments'), 'reference');

        // Only the completed one with nothing settled against it. A failed attempt has
        // nothing to settle, and including it would report an abandoned checkout as money
        // she is owed.
        $this->assertSame(['ref_owed'], $references);
    }

    /** The other half of the same bug: "false" must not read as true either. */
    public function test_the_unsettled_filter_off_returns_everything(): void
    {
        $this->payment('ref_owed', PaymentStatus::Completed, null);
        $this->payment('ref_settled', PaymentStatus::Completed, 12000);

        $response = $this->asStaff($this->kitchen())
            ->getJson('/api/v1/admin/payments?unsettled=false')
            ->assertOk();

        $this->assertCount(2, $response->json('data.payments'));
    }

    /* ── The payment mode ──────────────────────────────────────────────────── */

    /**
     * ⚠️ READING IT IS NOT AN ACT OF AUTHORITY, AND THIS IS A SAFETY TEST.
     *
     * `PaymentModeBanner` renders on every back-office screen and reads this endpoint. When
     * it required `settings.manage` — which the `admin` role rightly does not hold — the
     * "PAYMENTS ARE IN SIMULATE MODE" warning could never appear for the one person running
     * the shop. A warning that fails closed and silently is worse than no warning.
     */
    public function test_the_kitchen_can_read_the_payment_mode(): void
    {
        $this->asStaff($this->kitchen())
            ->getJson('/api/v1/admin/payment-mode')
            ->assertOk()
            ->assertJsonPath('data.mode', 'live');
    }

    /**
     * ⚠️ SHE CAN CHANGE IT TOO, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT.
     *
     * This test asserted the opposite when it was written, hours before this line: reading
     * the mode was opened up and writing it was left on `settings.manage`, which the `admin`
     * role did not hold. The result on screen was a switch that rendered, invited a press,
     * and answered "Couldn't change it. You may not have permission." — for the account that
     * belongs to the person who owns the shop.
     *
     * `admin` and `tech_admin` are one person in this business, so they now hold the same
     * list. See `Permission::byRole()` for what still restrains an admin, and
     * `RoleCeilingTest` for the tests that prove it.
     */
    public function test_the_kitchen_can_change_the_payment_mode(): void
    {
        $this->asStaff($this->kitchen())
            ->putJson('/api/v1/admin/payment-mode', ['mode' => 'simulate'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'simulate');
    }

    public function test_the_owner_can_change_the_payment_mode(): void
    {
        $this->asStaff($this->owner())
            ->putJson('/api/v1/admin/payment-mode', ['mode' => 'simulate'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'simulate');
    }

    /**
     * ⚠️ AND IT IS STILL AUDITED, WHICH IS NOW THE ONLY THING STANDING BEHIND IT.
     *
     * When the permission was the restraint, the audit row was a second line of defence.
     * With `admin` holding everything it is the first and last: "who put this in simulate
     * mode, and when" must have an answer that is not a guess.
     */
    public function test_changing_the_mode_is_recorded(): void
    {
        $this->asStaff($this->kitchen())
            ->putJson('/api/v1/admin/payment-mode', ['mode' => 'simulate'])
            ->assertOk();

        $this->assertDatabaseHas('audit_log', ['user_id' => $this->kitchen()->id]);
    }
}
