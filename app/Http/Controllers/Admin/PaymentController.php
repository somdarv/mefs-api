<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payment;
use App\Services\Money\SettlementImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The money that moved, and the money that arrived.
 *
 * Two different questions, and the gap between them is the point of this controller. What
 * Paystack charged is known the moment a webhook lands; what reached her bank is not known
 * until a settlement is recorded, and until then `settled_amount` is **null — unknown, not
 * zero**.
 */
final class PaymentController extends Controller
{
    use AuthorizesPermissions;

    public function __construct(private readonly SettlementImporter $importer) {}

    /**
     * Every attempt, filterable.
     *
     * ⚠️ ONE ROW PER ATTEMPT, NOT PER ORDER, and the filter on status is what makes that
     * useful rather than confusing: an order she is chasing has a `failed` row and possibly
     * an `abandoned` one, and finding those is the reason to open this screen at all.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::PaymentsView);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:80'],
            'unsettled' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->with(['order:id,order_number,contact_name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($sub) => $sub
                    ->where('reference', 'ilike', "%{$search}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'ilike', "%{$search}%")),
            ))
            /*
             * "Which completed payments have I not been paid for yet?" — the one question
             * the reconciliation screen exists to answer. Restricted to completed attempts
             * because a failed one has nothing to settle, and including them would report
             * every abandoned checkout as money she is owed.
             */
            ->when(
                $filters['unsettled'] ?? false,
                fn ($q) => $q->completed()->whereNull('settled_amount'),
            );

        $payments = (clone $query)->orderByDesc('id')->paginate($filters['per_page'] ?? 50);

        return ApiResponse::success([
            'payments' => PaymentResource::collection($payments->items()),
            'totals' => $this->totals(clone $query),
            'meta' => [
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
        ], 'Payments');
    }

    /**
     * Charged, settled, and the gap — over the whole filtered set, not just this page.
     *
     * ⚠️ `awaiting_settlement` COUNTS ROWS, IT DOES NOT SUM AN UNKNOWN. Summing
     * `settled_amount` over rows where it is null would treat "we have not been told yet" as
     * zero and report a shortfall that does not exist. The count is a fact; the amount is
     * not knowable, and saying so is the honest answer.
     */
    private function totals($query): array
    {
        $completed = (clone $query)->completed();

        return [
            'charged' => (int) (clone $completed)->sum('amount'),
            'fees' => (int) (clone $completed)->whereNotNull('fee')->sum('fee'),
            'settled' => (int) (clone $completed)->whereNotNull('settled_amount')->sum('settled_amount'),

            // What we have been told about, so `charged - settled` is only meaningful
            // against this subset rather than against everything ever taken.
            'charged_and_settled' => (int) (clone $completed)->whereNotNull('settled_amount')->sum('amount'),

            'awaiting_settlement_count' => (clone $completed)->whereNull('settled_amount')->count(),
            'awaiting_settlement_charged' => (int) (clone $completed)->whereNull('settled_amount')->sum('amount'),
        ];
    }

    /**
     * Record what actually landed, from a settlement export.
     *
     * A separate permission from reading the list — see `Permission::PaymentsReconcile`.
     * Reading what was charged is operational; asserting what was received is not, and a
     * settled column anyone can rewrite is not evidence of anything.
     *
     * The parsing, the rules and every way a row can fail live in `SettlementImporter`.
     * There is nothing here that decides whether a row may be written.
     */
    public function settle(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::PaymentsReconcile);

        $request->validate([
            // 2MB is thousands of rows. A settlement export larger than that is not a
            // settlement export.
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $result = $this->importer->import(
            SettlementImporter::parse($request->file('file')->get()),
        );

        return ApiResponse::success($result, sprintf(
            '%d settled, %d skipped',
            $result['summary']['settled'] ?? 0,
            array_sum($result['summary']) - ($result['summary']['settled'] ?? 0),
        ));
    }
}
