<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * The Phase 0 gate: `/api/v1/health` returns the envelope.
 */
final class HealthTest extends TestCase
{
    public function test_health_returns_the_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'status',
                    'app',
                    'environment',
                    'time',
                    'checks' => ['database' => ['ok', 'driver']],
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');
    }

    /**
     * The health check is only worth having if it actually reaches the database. A monitor
     * that reports OK while Postgres is unreachable is worse than none.
     */
    public function test_health_reports_the_live_database_connection(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.checks.database.ok', true)
            ->assertJsonPath('data.checks.database.driver', 'pgsql');
    }

    /**
     * Pins brief §11.2 — the suite must run against PostgreSQL. If someone "fixes" a slow
     * test run by switching phpunit.xml back to SQLite, this fails and says why.
     */
    public function test_the_suite_runs_against_postgres(): void
    {
        $this->assertSame(
            'pgsql',
            \DB::connection()->getDriverName(),
            'The test suite must run against PostgreSQL (brief §11.2). SQLite folds LIKE '
            .'case and cannot parse EXTRACT, so a green SQLite suite proves nothing about '
            .'the Postgres path.',
        );
    }

    public function test_an_unknown_api_route_is_enveloped_too(): void
    {
        $this->getJson('/api/v1/no-such-endpoint')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Not found.')
            ->assertJsonPath('data', null);
    }
}
