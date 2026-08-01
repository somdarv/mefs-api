<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Sms\OrderNotifier;
use Illuminate\Console\Command;

/**
 * "Your food is tomorrow."
 *
 * ⚠️ THE MESSAGE THIS BUSINESS NEEDS MOST, AND ONE A SAME-DAY KITCHEN WOULD NEVER WRITE.
 *
 * An order placed on the 1st for the 12th is eleven days out of mind. Somebody who forgets
 * does not merely miss their food — she has already cooked it, and a pre-order kitchen has
 * no walk-in trade to sell it to. This is the one text that prevents waste rather than
 * answering a question, and it is why the brief's "cutoff nudge" became this: a nudge before
 * the cutoff is a marketing message to people who have not ordered, and this is a service
 * message to people who have.
 */
final class SendCollectionReminders extends Command
{
    protected $signature = 'orders:remind-collection';

    protected $description = 'Text customers whose order is for tomorrow';

    public function handle(OrderNotifier $notifier): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $orders = Order::query()
            ->whereDate('fulfil_date', $tomorrow)
            /*
             * ⚠️ EXCLUDES TERMINAL STATUSES, WHICH IS WHAT KEEPS THIS FROM BEING WORSE THAN
             * NOTHING. A "collect tomorrow" text about an order that was cancelled last week
             * is the most confusing message this system could send, and it would go out on
             * exactly the orders somebody is already unhappy about.
             */
            ->whereNotIn('status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Completed->value,
                OrderStatus::Delivered->value,
            ])
            /*
             * ⚠️ AND EXCLUDES EXPIRED HOLDS. An unpaid order whose slot was already released
             * is not being cooked; telling its customer to come tomorrow promises food that
             * does not exist.
             */
            ->where('hold_expired', false)
            ->get();

        foreach ($orders as $order) {
            $notifier->collectionReminder($order);
        }

        $this->info("Reminded {$orders->count()} order(s) for {$tomorrow}.");

        return self::SUCCESS;
    }
}
