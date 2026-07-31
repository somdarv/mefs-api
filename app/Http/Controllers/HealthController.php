<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The Phase 0 gate: `/api/v1/health` returns the envelope.
 *
 * It checks the database rather than just returning a literal. A health endpoint that
 * reports OK while Postgres is unreachable is worse than none — it is a monitor that lies,
 * and the first thing anyone does with a green health check is stop looking.
 *
 * A failed check returns **503**, not 200-with-a-flag, so an uptime monitor that only reads
 * status codes still sees the outage.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();

        $payload = [
            'status' => $database['ok'] ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
            'checks' => [
                'database' => $database,
            ],
        ];

        return $database['ok']
            ? ApiResponse::success($payload, 'Healthy')
            : ApiResponse::error('Degraded', 503);
    }

    /**
     * @return array{ok: bool, driver: string, error?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return [
                'ok' => true,
                'driver' => DB::connection()->getDriverName(),
            ];
        } catch (Throwable $e) {
            // The message can carry a DSN, so it is logged in full and summarised here.
            report($e);

            return [
                'ok' => false,
                'driver' => (string) config('database.default'),
                'error' => 'unreachable',
            ];
        }
    }
}
