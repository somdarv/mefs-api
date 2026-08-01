<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Enums\OrderType;
use App\Models\Order;

/**
 * The three messages, written once.
 *
 * ⚠️ AN SMS IS 160 CHARACTERS AND SHE PAYS PER SEGMENT. Every message here is built to fit
 * one, which is also why none of them repeats what the customer already knows. The order
 * number goes first because it is the thing they will read back down the phone.
 *
 * No links. A short link would be nice and would cost a segment; the kitchen's number is
 * more useful to somebody standing in a queue anyway.
 */
final class OrderMessages
{
    public function __construct(private readonly string $kitchenPhone) {}

    /**
     * Sent the moment the order exists.
     *
     * Says what it is for, not just that it worked — "your order is in" with no date is a
     * message the customer has to come back and check.
     */
    public function confirmation(Order $order): string
    {
        $when = $order->fulfil_date !== null
            ? $order->fulfil_date->format('D j M')
            : 'soon';

        $how = match ($order->order_type) {
            OrderType::Pickup => 'Collect',
            OrderType::Delivery => 'Delivery',
            OrderType::Shipping => 'Shipping',
        };

        $total = $this->money($order->total);

        return "Mef's: order {$order->order_number} received. {$how} {$when}. Total {$total}."
            .($order->is_paid ? '' : " We'll be in touch about payment.");
    }

    /**
     * The unpaid nudge, sent before the hold runs out.
     *
     * ⚠️ Only ever for HER manual orders. A customer-placed order has a 30-minute payment
     * window and is mid-checkout — texting somebody who is looking at the payment screen is
     * noise. Enforced by the caller, not here.
     */
    public function paymentReminder(Order $order): string
    {
        $total = $this->money($order->total);

        return "Mef's: order {$order->order_number} ({$total}) is holding your slot until "
            .($order->slot_hold_expires_at?->format('g:ia') ?? 'shortly')
            .". Call {$this->kitchenPhone} to pay.";
    }

    /** Cooked, and the next move depends on who is carrying it. */
    public function ready(Order $order): string
    {
        return match ($order->order_type) {
            OrderType::Pickup => "Mef's: order {$order->order_number} is ready to collect. "
                .($order->branchSnapshot()['address'] ?? ''),
            OrderType::Delivery => "Mef's: order {$order->order_number} is on its way to you.",
            OrderType::Shipping => "Mef's: order {$order->order_number} has been shipped.",
        };
    }

    /**
     * "Your food is tomorrow."
     *
     * ⚠️ THE MESSAGE THIS BUSINESS NEEDS MOST AND A SAME-DAY KITCHEN WOULD NEVER SEND.
     *
     * An order placed on the 1st for the 12th is eleven days out of mind. Somebody who
     * forgets does not just miss their food — she has already cooked it, and a pre-order
     * kitchen has no walk-in trade to sell it to. This is the one text that prevents waste
     * rather than answering a question.
     *
     * It says the time and the place, because "you have an order tomorrow" sends them back
     * to the site to find out where and when — and the whole point is to save that trip.
     */
    public function collectionReminder(Order $order): string
    {
        $when = $order->fulfil_date?->format('D j M') ?? 'tomorrow';

        return match ($order->order_type) {
            OrderType::Pickup => "Mef's: reminder — order {$order->order_number} is ready to "
                ."collect {$when} at ".($order->branchSnapshot()['address'] ?? 'the kitchen').'.',
            default => "Mef's: reminder — order {$order->order_number} is coming to you {$when}.",
        };
    }

    public function cancelled(Order $order): string
    {
        return "Mef's: order {$order->order_number} has been cancelled. "
            ."Call {$this->kitchenPhone} if that's not right.";
    }

    /**
     * "₵40.00" from 4000.
     *
     * The only place on this side that divides by 100, matching the frontend's formatter.
     * Integer pesewas everywhere else, without exception.
     */
    private function money(int $pesewas): string
    {
        return 'GHS '.number_format($pesewas / 100, 2);
    }
}
