<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Ordering\IllegalTransition;
use App\Services\Ordering\OrderStatusMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give back the slots nobody paid for (departure #6).
 *
 * ⚠️ WITHOUT THIS, `slot_hold_expires_at` IS A COLUMN NOTHING HONOURS.
 *
 * Every order is written with an expiry — 30 minutes for a customer's payment window, two
 * hours for an order she took by phone. The column has been there since the schema landed
 * and, until now, nothing read it on a timer: an abandoned Paystack tab held its portions
 * forever, and the capacity numbers `CycleGate` refuses orders against were quietly wrong in
 * the direction that loses sales.
 *
 * A piece of machinery that looks live and is inert is worse than one that is absent, which
 * is the argument this codebase makes about gates everywhere else. Same argument here.
 *
 * ── WHAT IT DOES NOT DO ───────────────────────────────────────────────────────
 *
 * Touch anything she has accepted. `Order::scopeHoldExpired()` is `received`-only: accepting
 * is a commitment, and an automated job that cancels food already being planned is wrong in
 * the one direction that cannot be undone. See the scope for the full reasoning.
 */
final class ReleaseExpiredHolds extends Command
{
    protected $signature = 'orders:release-expired-holds
                            {--dry-run : List what would be released without releasing it}';

    protected $description = 'Cancel unpaid orders whose slot hold has expired, returning their capacity';

    public function handle(OrderStatusMachine $statuses): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Chunked by id: this runs on a timer against a table that only grows, and a
        // `->get()` that is fine for a year is not fine for the year after.
        $released = 0;
        $failed = 0;

        Order::query()
            ->holdExpired()
            ->with('items')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($statuses, $dryRun, &$released, &$failed): void {
                foreach ($orders as $order) {
                    $this->line("  {$order->order_number}  held until {$order->slot_hold_expires_at?->toIso8601String()}");

                    if ($dryRun) {
                        $released++;

                        continue;
                    }

                    try {
                        // Cancelling is what actually returns the capacity: the status
                        // machine hands the portions back to `cycle_day_items`, and
                        // `scopeHoldingCapacity` stops counting the order against the day.
                        // Flagging alone would leave the slot occupied by a dead order.
                        $statuses->transition(
                            $order,
                            OrderStatus::Cancelled,
                            null,
                            'Slot hold expired before payment',
                        );

                        // Set AFTER the transition, so an order that failed to move is not
                        // marked as handled. `hold_expired` is what tells her the difference
                        // between "the customer cancelled" and "the clock did", which is the
                        // one she may want to ring back about.
                        $order->hold_expired = true;
                        $order->save();

                        $released++;
                    } catch (IllegalTransition $e) {
                        // Reachable if the order moved between the query and here. Counted
                        // and logged rather than swallowed — a job that silently skips work
                        // is indistinguishable from a job that had none to do.
                        $failed++;

                        Log::warning('Could not release an expired hold', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'status' => $order->status->value,
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $verb = $dryRun ? 'would release' : 'released';
        $this->info("Expired holds: {$verb} {$released}".($failed > 0 ? ", {$failed} failed" : '').'.');

        if ($failed > 0) {
            // A non-zero exit so a scheduler that watches exit codes actually notices.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
