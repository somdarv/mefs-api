<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Record an act of authority.
 *
 * ⚠️⚠️ WRITING TO THIS LOG MUST NEVER FAIL THE THING BEING LOGGED. ⚠️⚠️
 *
 * That is the whole design of this class and it is worth being explicit about, because the
 * instinct runs the other way — an audit trail feels like it should be a hard requirement,
 * and "refuse the action if we cannot record it" sounds rigorous.
 *
 * It is not. Every call site here is an act that has *already been decided*: the week is
 * being reopened, the price is being changed, the settlement is being imported. Throwing
 * from the logging call means a full jsonb column, a schema drift, or a bad `before`
 * snapshot takes down repricing a dish — and the failure surfaces as a 500 on an action that
 * had nothing wrong with it, which is a strictly worse outcome than a gap in the log.
 *
 * So it catches, and reports the failure to the application log, where a missing audit row
 * becomes a monitoring problem rather than an outage. Law 7's spirit: a check that cannot
 * evaluate must not block the work.
 *
 * ── WHAT GOES IN HERE ─────────────────────────────────────────────────────────
 *
 * Acts of authority, not events. Forcing a closed week open, repricing a dish, minting a
 * discount code, asserting what landed in the bank, changing what a member of staff may do.
 * If the answer to "who would ever ask who did this" is nobody, it does not belong here —
 * see the migration for why a log of everything is not an audit trail.
 */
final class AuditLog
{
    public function record(
        string $action,
        string $summary,
        ?User $actor = null,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        try {
            /*
             * ⚠️ WRAPPED IN ITS OWN TRANSACTION, WHICH INSIDE AN EXISTING ONE IS A SAVEPOINT
             * — AND WITHOUT IT THE `catch` BELOW IS A LIE ON POSTGRES.
             *
             * Postgres aborts the *whole* transaction on any failed statement: every command
             * after it returns "current transaction is aborted" until a rollback. So a
             * caught insert failure inside a caller's transaction still takes the caller
             * down, several statements later, with an error that points nowhere near here.
             *
             * A savepoint contains it. The audit row rolls back on its own and the work that
             * was being logged commits, which is the promise this class makes.
             */
            DB::transaction(fn () => AuditEntry::query()->create([
                'user_id' => $actor?->id,
                // Snapshot, not a join. A rename must not rewrite who did what.
                'actor_name' => $actor?->name,

                'action' => $action,
                'subject_type' => $subject === null ? null : $subject::class,
                'subject_id' => $subject?->getKey(),

                'summary' => $summary,
                'before' => $before,
                'after' => $after,
                'reason' => $reason,

                'ip' => request()?->ip(),
                'created_at' => now(),
            ]));
        } catch (Throwable $e) {
            // Reported, never rethrown. See the warning at the top of this class.
            Log::error('Audit write failed', [
                'action' => $action,
                'summary' => $summary,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The before/after pair for a set of fields, with **unchanged fields dropped**.
     *
     * ⚠️ A ROW SHOWING TWELVE FIELDS OF WHICH ONE MOVED IS A ROW NOBODY READS. Narrowing to
     * what actually changed is what makes the log skimmable, and skimmable is the only
     * property that matters in something consulted once a quarter.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function changes(array $before, array $after): array
    {
        $keys = array_keys($after);
        $changedBefore = [];
        $changedAfter = [];

        foreach ($keys as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;

            // Loose-ish comparison on scalars only: `4000` from a form and `4000` from the
            // database differ in type often enough that a strict check would report every
            // save as a change and defeat the narrowing above.
            if (is_scalar($was) && is_scalar($now) ? (string) $was !== (string) $now : $was !== $now) {
                $changedBefore[$key] = $was;
                $changedAfter[$key] = $now;
            }
        }

        return [$changedBefore, $changedAfter];
    }
}
