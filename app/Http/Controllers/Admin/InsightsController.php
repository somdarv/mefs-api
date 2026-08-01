<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Money\Insights;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What did I actually make?"
 *
 * ⚠️ `analytics.view` IS A SEPARATE PERMISSION FROM `orders.view`, AND THIS ROUTE IS THE
 * WHOLE REASON IT EXISTS.
 *
 * In the original, analytics sat behind `view_orders` — held by cashiers, kitchen staff and
 * riders alike — while `view_analytics` was granted to exactly the right roles and referenced
 * by **zero** routes. It looked enforced. It was decoration, and every person who could see
 * an order could see the company's revenue (brief §4.3.4).
 *
 * `PermissionCoverageTest` matches the enum case below, which is the mechanism that stops it
 * happening again: the permission is only ever "covered" by a real check.
 */
final class InsightsController extends Controller
{
    use AuthorizesPermissions;

    public function __construct(private readonly Insights $insights) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::AnalyticsView);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        /*
         * Default to the last 30 days rather than to all time.
         *
         * A dashboard that opens on every order ever placed answers a question nobody asked
         * and gets slower every week it runs. Thirty days is roughly four cooking cycles —
         * enough to see a trend, short enough to be about now.
         */
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::now();
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])
            : $to->subDays(29);

        // A backwards window returns nothing and looks exactly like a quiet month. Say so.
        abort_if($from->greaterThan($to), 422, 'The start of the window is after its end.');

        return ApiResponse::success($this->insights->between($from, $to), 'Insights');
    }
}
