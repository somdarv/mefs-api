<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Models\OrderCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⚠️ A `timestamptz` ROUND TRIP MUST NOT SHIFT.
 *
 * Laravel serialises datetimes as 'Y-m-d H:i:s' with NO offset. Postgres interprets that
 * string, for a timestamptz column, in the SESSION timezone — which defaults to the
 * database server's locale. On the machine this was written on that default is
 * Europe/London, so an 18:00 UTC cutoff was stored as 17:00 UTC through the summer and
 * 18:00 through the winter.
 *
 * The damage is quiet and total: every ordering deadline off by an hour for half the year,
 * differing between two developers' machines, and correct in tests that use relative
 * offsets ("now plus two hours") because both ends shift together.
 *
 * `config/database.php` pins the connection to UTC. These tests fail if that is removed.
 */
final class TimestampTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connection_session_timezone_is_utc(): void
    {
        $this->assertSame(
            'UTC',
            DB::selectOne('SHOW TimeZone')->TimeZone,
            'The Postgres session timezone is not UTC. Every timestamptz written without an '.
            "explicit offset will be interpreted in that zone instead. Restore 'timezone' ".
            'on the pgsql connection in config/database.php.',
        );
    }

    public function test_an_exact_instant_survives_a_write_and_a_read(): void
    {
        $closesAt = CarbonImmutable::parse('2026-08-04T18:00:00Z');

        $cycle = OrderCycle::query()->create([
            'name' => 'Timezone probe',
            'slug' => 'timezone-probe',
            'service_start_date' => '2026-08-05',
            'service_end_date' => '2026-08-12',
            'orders_open_at' => CarbonImmutable::parse('2026-08-01T00:00:00Z'),
            'orders_close_at' => $closesAt,
            'status' => 'draft',
        ]);

        $this->assertTrue(
            $closesAt->equalTo($cycle->fresh()->orders_close_at),
            'The cutoff shifted between writing and reading it. Expected '
            .$closesAt->toIso8601String().', got '.$cycle->fresh()->orders_close_at->toIso8601String(),
        );
    }

    /**
     * The summer case specifically. A zone with no DST would round-trip fine year-round and
     * hide the bug; Europe/London in August is exactly where it bites.
     */
    public function test_a_summer_instant_does_not_gain_or_lose_an_hour(): void
    {
        $cycle = OrderCycle::query()->create([
            'name' => 'Summer probe',
            'slug' => 'summer-probe',
            'service_start_date' => '2026-07-06',
            'service_end_date' => '2026-07-10',
            'orders_open_at' => CarbonImmutable::parse('2026-07-01T00:00:00Z'),
            'orders_close_at' => CarbonImmutable::parse('2026-07-05T18:00:00Z'),
            'status' => 'draft',
        ]);

        $this->assertSame('18:00', $cycle->fresh()->orders_close_at->utc()->format('H:i'));
    }
}
