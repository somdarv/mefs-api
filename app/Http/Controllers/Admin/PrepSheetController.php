<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Ordering\PrepSheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What am I cooking on Wednesday?"
 *
 * One question, one endpoint, no filters beyond the date. The aggregation and every way of
 * getting it wrong live in `PrepSheet` — a controller that assembled this itself would be a
 * second definition of "which orders count", and this codebase has exactly one (§5.8).
 *
 * `orders.view`, not a new permission: it is the same underlying fact as the order list,
 * totalled differently.
 */
final class PrepSheetController extends Controller
{
    use AuthorizesPermissions;

    public function __construct(private readonly PrepSheet $sheet) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::OrdersView);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = CarbonImmutable::parse($data['date']);

        return ApiResponse::success(
            $this->sheet->forDate($date),
            "Prep sheet for {$date->format('D j M')}",
        );
    }
}
