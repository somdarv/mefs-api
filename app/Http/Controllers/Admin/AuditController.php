<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Concerns\AuthorizesPermissions;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who did what.
 *
 * ⚠️ READ-ONLY, AND THERE IS NO OTHER VERB ON THIS ROUTE. No update, no delete, not even for
 * `tech_admin`. An audit row that the most privileged account can edit is not evidence — it
 * is a note, and the first thing anyone covering their tracks would reach for.
 *
 * `audit.view` is `tech_admin` only. It is not on the admin role, and that is deliberate:
 * the log exists partly to record what *she* does, and a log its subject can curate answers
 * a different question from the one it was built for.
 */
final class AuditController extends Controller
{
    use AuthorizesPermissions;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizePermission($request, Permission::AuditView);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = AuditEntry::query()
            ->with('user:id,name')
            /*
             * A prefix match, so `action=promo` finds `promo.created` and `promo.updated`
             * without the client needing to know every verb. Anchored with `like 'x%'`
             * rather than `%x%`: an unanchored match would make `menu` find
             * `cycle.menu_something` and it cannot use an index.
             */
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::success([
            'entries' => array_map($this->present(...), $entries->items()),
            'meta' => [
                'total' => $entries->total(),
                'per_page' => $entries->perPage(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
            ],
        ], 'Audit log');
    }

    /**
     * ⚠️ `actor` FALLS BACK TO THE SNAPSHOT, NOT TO THE JOIN.
     *
     * `user_id` nulls out when a staff account is deleted, and the relation goes with it —
     * but `actor_name` was written at the time and survives. Rendering "Unknown" for
     * somebody whose account was closed last year would quietly erase them from their own
     * history. Null there genuinely means the system did it.
     *
     * @return array<string, mixed>
     */
    private function present(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'summary' => $entry->summary,
            'reason' => $entry->reason,

            'actor' => $entry->user?->name ?? $entry->actor_name,
            'actor_id' => $entry->user_id,
            'is_system' => $entry->user_id === null && $entry->actor_name === null,

            'subject_type' => $entry->subject_type === null
                ? null
                : class_basename($entry->subject_type),
            'subject_id' => $entry->subject_id,

            'before' => $entry->before,
            'after' => $entry->after,

            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
