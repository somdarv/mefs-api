<?php

declare(strict_types=1);

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner's decision, on 2026-08-18: a 1% service charge, on.
 *
 * ── WHY A MIGRATION AND NOT THE SEEDER ────────────────────────────────────────
 *
 * `SystemSettingSeeder` uses `firstOrCreate`, and its own docblock says why: the seeder owns
 * which settings *exist*, she owns what they *are*. Production's rows were created at
 * provisioning, so the seeder will never touch them again — changing its `'value' => '0'` to
 * `'1'` would alter what a brand-new install starts as and leave the live shop at zero, which
 * is the exact opposite of the intent. A dated migration is also the honest record: this is a
 * decision someone made on a day, not a property of a fresh database.
 *
 * ⚠️ IT UPDATES AND DELIBERATELY DOES NOT INSERT, AND THAT IS NOT AN OVERSIGHT.
 *
 * An `updateOrInsert` here would be tidier and would break the test suite. Migrations run
 * before seeders on a fresh database, so on every `RefreshDatabase` this would insert the two
 * rows itself, the seeder's `firstOrCreate` would then find them already present and leave
 * them enabled, and a 1% charge would appear in every exact-total assertion in the suite —
 * dozens of tests failing on arithmetic that is actually correct. Update-only makes this a
 * no-op on a fresh database and effective on every provisioned one, which is precisely the
 * set of environments it is meant to change.
 *
 * The cap is left at its seeded 500 pesewas, so this is **1% capped at GHS 5.00** — the cap
 * only binds above a GHS 500 subtotal. That was already her setting and this migration has no
 * opinion about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->set('service_charge_percent', '1');

        // Enabled last. `PriceCalculator` checks the flag before the percentage, so ordering
        // it this way means there is no instant at which the flag is on with a percent of 0 —
        // not that anyone would notice a zero charge, but the reverse order would briefly
        // charge 1% while the intended percent was still being written.
        $this->set('service_charge_enabled', '1');

        // ⚠️ `SystemSetting::all_cached()` is `rememberForever`, and production runs the
        // database cache store — so the old values survive a deploy unless this is flushed.
        // `config:clear` in the deploy script does not touch the application cache.
        SystemSetting::flush();
    }

    public function down(): void
    {
        $this->set('service_charge_enabled', '0');
        $this->set('service_charge_percent', '0');

        SystemSetting::flush();
    }

    /** Update in place, never insert. See the class docblock. */
    private function set(string $key, string $value): void
    {
        DB::table('system_settings')->where('key', $key)->update(['value' => $value]);
    }
};
