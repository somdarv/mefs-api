<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Enums\CycleStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\MenuItem;
use App\Models\OrderCycle;
use App\Models\User;
use App\Services\Ordering\CycleBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Who did what.
 *
 * ⚠️ TWO OF THESE ARE THE POINT, AND THEY PULL IN OPPOSITE DIRECTIONS.
 *
 *  - `test_a_forced_override_is_recorded_with_its_reason` — the log has to catch the acts of
 *    authority. Forcing a week back open is the one thing that lets the kitchen take a job
 *    it had already refused.
 *  - `test_renaming_a_size_is_not_an_audit_row` — and it has to catch *only* those. A log of
 *    everything buries the handful anyone comes looking for, which makes it disk usage that
 *    looks like diligence.
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private OrderCycle $cycle;

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
    }

    private function admin(): User
    {
        return User::query()->where('email', 'mef@mefs.local')->firstOrFail();
    }

    private function owner(): User
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::TechAdmin->value))->firstOrFail();
    }

    private function asStaff(User $user): static
    {
        return $this->withToken($user->createToken('test', ['staff'])->plainTextToken);
    }

    // ── The acts that are recorded ────────────────────────────────────────────

    /**
     * ⚠️ THE MOST AUDIT-WORTHY ACT IN THE APPLICATION, and the reason travels with it.
     * "Why were we open on Friday night" is exactly the question this row answers.
     */
    public function test_a_forced_override_is_recorded_with_its_reason(): void
    {
        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/cycles/{$this->cycle->id}/override", [
                'override' => 'force_open',
                'reason' => 'Regulars called after the cutoff',
            ])
            ->assertOk();

        $entry = AuditEntry::query()->where('action', 'cycle.override')->firstOrFail();

        $this->assertSame('Regulars called after the cutoff', $entry->reason);
        $this->assertSame($this->admin()->id, $entry->user_id);
        $this->assertSame($this->admin()->name, $entry->actor_name);
        $this->assertNull($entry->before['override']);
        $this->assertSame('force_open', $entry->after['override']);
        $this->assertStringContainsString('Week of 5 Aug', $entry->summary);
    }

    /** A reprice carries what it moved from. That is unanswerable once the row is written. */
    public function test_a_reprice_records_the_old_price(): void
    {
        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        $option = $item->options()->firstOrFail();
        $was = $option->price;

        $this->asStaff($this->admin())
            ->putJson("/api/v1/admin/menu/items/{$item->id}/options", [
                'options' => [[
                    'option_key' => $option->option_key,
                    'label' => $option->label,
                    'size_label' => $option->size_label,
                    'price' => $was + 500,
                ]],
            ])
            ->assertOk();

        $entry = AuditEntry::query()->where('action', 'menu.repriced')->firstOrFail();

        $this->assertSame($was, $entry->before[$option->option_key]);
        $this->assertSame($was + 500, $entry->after[$option->option_key]);
    }

    public function test_minting_a_discount_code_records_its_terms(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/promos', [
                'code' => 'LAUNCH',
                'type' => 'percentage',
                'value' => 20,
                'usage_limit' => 50,
            ])
            ->assertCreated();

        $entry = AuditEntry::query()->where('action', 'promo.created')->firstOrFail();

        $this->assertStringContainsString('LAUNCH', $entry->summary);
        $this->assertStringContainsString('20% off', $entry->summary);
        $this->assertStringContainsString('50 uses', $entry->summary);
        $this->assertSame(20, $entry->after['value']);
    }

    // ── ⚠️ And the acts that are not ──────────────────────────────────────────

    /**
     * ⚠️ THE OPTIONS ENDPOINT WRITES LABELS AND PRICES TOGETHER, so it is hit every time she
     * renames a size. If those all landed in the log, the repricings — the only thing on
     * that endpoint anyone will ever look for — would be buried under routine edits.
     */
    public function test_renaming_a_size_is_not_an_audit_row(): void
    {
        $item = MenuItem::query()->where('slug', 'waakye')->firstOrFail();
        $option = $item->options()->firstOrFail();

        $this->asStaff($this->admin())
            ->putJson("/api/v1/admin/menu/items/{$item->id}/options", [
                'options' => [[
                    'option_key' => $option->option_key,
                    'label' => 'Regular portion',
                    'size_label' => $option->size_label,
                    // Unchanged.
                    'price' => $option->price,
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            0,
            AuditEntry::query()->where('action', 'menu.repriced')->count(),
            'A rename was logged as a reprice.',
        );
    }

    /** Content is not an act of authority. Nobody asks who changed a banner's wording. */
    public function test_editing_a_banner_is_not_an_audit_row(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/banners', ['title' => 'Jollof base is here'])
            ->assertCreated();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    // ── Reading it ────────────────────────────────────────────────────────────

    /**
     * ⚠️ SHE CAN READ IT NOW, AND THIS IS THE GRANT WITH THE BEST ARGUMENT AGAINST IT.
     *
     * The old rule was that `audit.view` stayed off the admin role, because the log exists
     * partly to record what she does and a log its own subject can read and curate answers a
     * different question from the one it was built for.
     *
     * Two things retire that argument here, and only here:
     *
     *  1. **She cannot curate it.** The next test pins that there is no route to edit or
     *     delete a row, for any verb, for any account including the owner's. Reading is not
     *     curating when the record is append-only by construction.
     *  2. **There is no second subject.** `admin` and `tech_admin` are one person in this
     *     business. Withholding the log from her hid her acts from nobody — the only other
     *     account is hers as well.
     *
     * ⚠️ IF A SECOND OPERATOR IS EVER HIRED, THIS IS THE FIRST LINE TO REVISIT. The argument
     * above is entirely contingent on there being one person, and it stops holding the day
     * that changes. That is a fourth role, not a clawing-back of this one.
     */
    public function test_both_accounts_can_read_the_audit_log(): void
    {
        $this->asStaff($this->admin())->getJson('/api/v1/admin/audit')->assertOk();
        $this->forgetAuth();

        $this->asStaff($this->owner())->getJson('/api/v1/admin/audit')->assertOk();
    }

    /** A prefix match, so `promo` finds every verb without the client knowing them all. */
    public function test_the_log_filters_by_action_prefix(): void
    {
        $this->asStaff($this->admin())
            ->postJson('/api/v1/admin/promos', ['code' => 'A', 'type' => 'fixed', 'value' => 100])
            ->assertCreated();

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/cycles/{$this->cycle->id}/override", [
                'override' => 'force_closed',
                'reason' => 'Power cut',
            ])->assertOk();

        $this->forgetAuth();

        $response = $this->asStaff($this->owner())
            ->getJson('/api/v1/admin/audit?action=promo')
            ->assertOk();

        $this->assertCount(1, $response->json('data.entries'));
        $this->assertSame('promo.created', $response->json('data.entries.0.action'));
    }

    /**
     * ⚠️ APPEND-ONLY. There is no PATCH and no DELETE on this route for anyone — including
     * the account that can read it. A row the most privileged user can edit is not evidence.
     */
    public function test_there_is_no_way_to_edit_or_delete_an_audit_row(): void
    {
        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/cycles/{$this->cycle->id}/override", [
                'override' => 'force_open',
                'reason' => 'Regulars called',
            ])->assertOk();

        $id = AuditEntry::query()->firstOrFail()->id;
        $this->forgetAuth();

        /*
         * 404 — the path does not exist at all, for any verb. Not 403, which would mean the
         * capability exists and is merely withheld from this caller, and not 405, which
         * would mean the resource exists and only this verb is refused. Asserted with the
         * account that CAN read the log, so it is the route that is absent rather than the
         * permission.
         */
        $this->asStaff($this->owner())->patchJson("/api/v1/admin/audit/{$id}", [])->assertNotFound();
        $this->forgetAuth();
        $this->asStaff($this->owner())->deleteJson("/api/v1/admin/audit/{$id}")->assertNotFound();

        $this->assertSame(1, AuditEntry::query()->count());
    }

    /**
     * ⚠️ A FAILED AUDIT WRITE MUST NOT FAIL THE ACT BEING LOGGED.
     *
     * The instinct runs the other way — an audit trail feels like it should be a hard
     * requirement. But every call site is an act already decided, so throwing here turns a
     * logging problem into a 500 on an override that had nothing wrong with it. A gap in the
     * log is the cheaper failure, and it is one monitoring can see.
     *
     * ⚠️ AND THIS TEST ONLY PASSES BECAUSE OF THE SAVEPOINT IN `AuditLog::record()`. It runs
     * inside `RefreshDatabase`'s transaction, and Postgres aborts a whole transaction on any
     * failed statement — so without the savepoint the caught failure still poisons
     * everything after it. That is not a test artefact: it is exactly what happens in
     * production when `record()` is called from inside a caller's transaction.
     */
    public function test_a_broken_audit_write_does_not_break_the_override(): void
    {
        // The table is gone. Nothing about the override itself has changed.
        Schema::drop('audit_log');

        $this->asStaff($this->admin())
            ->postJson("/api/v1/admin/cycles/{$this->cycle->id}/override", [
                'override' => 'force_open',
                'reason' => 'Regulars called after the cutoff',
            ])
            ->assertOk();

        $this->assertSame('force_open', $this->cycle->refresh()->override->value);
    }
}
