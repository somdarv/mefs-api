<?php

declare(strict_types=1);

namespace Tests\Feature\Money;

use App\Enums\Permission;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Settlement reconciliation — what actually landed in the bank.
 *
 * ⚠️ THE RULE THESE TESTS PIN: a structural problem refuses the WHOLE file; a per-row
 * mismatch is reported and skipped.
 *
 * A settlement batch legitimately contains references this system has never seen, so an
 * unknown reference cannot be fatal. But an unparseable amount means the wrong file or the
 * wrong column mapping, and importing 197 of 200 rows would leave her to work out which
 * three — with money.
 */
final class SettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-08-02T10:00:00Z'));
    }

    private function admin(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    private function asStaff(User $user): static
    {
        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    /** A completed payment with nothing settled against it yet. */
    private function payment(string $reference, int $amount = 12000): Payment
    {
        $order = Order::factory()->create();

        return Payment::query()->create([
            'order_id' => $order->id,
            'provider' => 'paystack',
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'GHS',
            'status' => PaymentStatus::Completed->value,
            'paid_at' => now(),
        ]);
    }

    private function upload(string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($this->admin())->post(
            '/api/v1/admin/payments/settlements',
            ['file' => UploadedFile::fake()->createWithContent('settlement.csv', $csv)],
        );
    }

    // ── The happy path, and its one conversion ────────────────────────────────

    /**
     * ⚠️ "118.50" IS 11850 PESEWAS.
     *
     * The importer is the one conversion boundary on this path. A float reaching the
     * database is a rounding error in somebody's takings, and 118.50 is not exactly
     * representable — so it is rounded after multiplying rather than truncated, which would
     * lose a pesewa per row.
     */
    public function test_a_settlement_file_writes_what_landed(): void
    {
        $payment = $this->payment('mefs_abc123', 12000);

        $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_abc123,118.50,2026-08-06
        CSV)
            ->assertOk()
            ->assertJsonPath('data.summary.settled', 1);

        $payment->refresh();

        $this->assertSame(11850, $payment->settled_amount);
        $this->assertSame('2026-08-06', $payment->settled_at->toDateString());
    }

    /** Paystack's own header spellings, which is what she will actually export. */
    public function test_it_accepts_the_documented_header_aliases(): void
    {
        $payment = $this->payment('mefs_alias', 5000);

        $this->upload(<<<'CSV'
        Transaction Reference,Net Amount,Settlement Date
        mefs_alias,48.00,2026-08-06
        CSV)
            ->assertOk()
            ->assertJsonPath('data.summary.settled', 1);

        $this->assertSame(4800, $payment->refresh()->settled_amount);
    }

    // ── Rows that are reported, not fatal ─────────────────────────────────────

    /**
     * A batch can legitimately carry references from before this system existed. Skipped and
     * named, never fatal — otherwise she can never import a real file.
     */
    public function test_an_unknown_reference_is_reported_and_the_file_still_imports(): void
    {
        $known = $this->payment('mefs_known', 5000);

        $response = $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_known,48.00,2026-08-06
        some_other_system,10.00,2026-08-06
        CSV)
            ->assertOk();

        $this->assertSame(1, $response->json('data.summary.settled'));
        $this->assertSame(1, $response->json('data.summary.unknown_reference'));
        $this->assertSame(4800, $known->refresh()->settled_amount);
    }

    /**
     * ⚠️ RE-IMPORTING THE SAME FILE IS THE MOST LIKELY THING TO HAPPEN TO IT, and it must be
     * a no-op rather than a rewrite.
     */
    public function test_reimporting_the_same_file_changes_nothing(): void
    {
        $payment = $this->payment('mefs_twice', 5000);

        $csv = <<<'CSV'
        reference,settled_amount,settled_at
        mefs_twice,48.00,2026-08-06
        CSV;

        $this->upload($csv)->assertOk()->assertJsonPath('data.summary.settled', 1);
        $this->upload($csv)->assertOk()->assertJsonPath('data.summary.unchanged', 1);

        $this->assertSame(4800, $payment->refresh()->settled_amount);
    }

    /**
     * ⚠️ A CONTRADICTION IS NEVER SILENTLY OVERWRITTEN. A settled figure that changes on
     * re-import is a number nobody can rely on afterwards.
     */
    public function test_a_contradicting_amount_is_refused_for_that_row_and_left_alone(): void
    {
        $payment = $this->payment('mefs_conflict', 5000);

        $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_conflict,48.00,2026-08-06
        CSV)->assertOk();

        $response = $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_conflict,40.00,2026-08-06
        CSV)->assertOk();

        $this->assertSame(1, $response->json('data.summary.conflict'));
        $this->assertSame(4800, $payment->refresh()->settled_amount, 'The original figure was overwritten.');
    }

    /** A payout is never larger than the charge — fees only ever come off. */
    public function test_a_settlement_larger_than_the_charge_is_refused(): void
    {
        $payment = $this->payment('mefs_toobig', 5000);

        $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_toobig,500.00,2026-08-06
        CSV)
            ->assertOk()
            ->assertJsonPath('data.summary.implausible', 1);

        $this->assertNull($payment->refresh()->settled_amount);
    }

    // ── Structural problems: the whole file goes back ─────────────────────────

    public function test_a_missing_column_refuses_the_file_and_says_what_it_found(): void
    {
        $this->payment('mefs_x');

        $response = $this->upload(<<<'CSV'
        reference,paid_out,when
        mefs_x,48.00,2026-08-06
        CSV)->assertStatus(422);

        $this->assertStringContainsString('settled_amount', $response->json('errors.file.0'));
        $this->assertStringContainsString('paid_out', $response->json('errors.file.0'));
    }

    /**
     * ⚠️ "GHS 1,250.00" IS REFUSED RATHER THAN STRIPPED.
     *
     * Stripping a separator means guessing at the locale, and guessing wrong turns 1,250
     * cedis into 1250 pesewas with no way to tell afterwards which happened.
     */
    public function test_an_unparseable_amount_refuses_the_whole_file(): void
    {
        $good = $this->payment('mefs_good', 5000);
        $this->payment('mefs_bad', 5000);

        $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_good,48.00,2026-08-06
        mefs_bad,"GHS 1,250.00",2026-08-06
        CSV)->assertStatus(422);

        $this->assertNull(
            $good->refresh()->settled_amount,
            'A structural failure must not leave half a file imported.',
        );
    }

    // ── Who may do it ─────────────────────────────────────────────────────────

    /**
     * ⚠️ READING WHAT WAS CHARGED AND ASSERTING WHAT WAS RECEIVED ARE DIFFERENT GRANTS.
     *
     * A settled column anyone who can view payments may rewrite is not evidence of anything.
     */
    public function test_settling_needs_more_than_permission_to_view_payments(): void
    {
        $payment = $this->payment('mefs_perm', 5000);

        SpatieRole::findByName(Role::Admin->value, 'web')
            ->revokePermissionTo(Permission::PaymentsReconcile->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->forgetAuth();

        $this->asStaff($this->admin())->getJson('/api/v1/admin/payments')->assertOk();
        $this->forgetAuth();

        $this->upload(<<<'CSV'
        reference,settled_amount,settled_at
        mefs_perm,48.00,2026-08-06
        CSV)->assertForbidden();

        $this->assertNull($payment->refresh()->settled_amount);
    }
}
