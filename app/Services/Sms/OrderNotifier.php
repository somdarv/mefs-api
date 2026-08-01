<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Enums\OrderSource;
use App\Jobs\SendSms;
use App\Models\Order;
use App\Support\GhanaPhone;

/**
 * When a customer gets a text, and which one.
 *
 * The policy lives here rather than at the call sites, because "does this order warrant an
 * SMS" is a question with the same answer everywhere and three places asking it separately
 * will eventually give three answers.
 *
 * ⚠️ NOTHING HERE CAN FAIL A CALLER. Every method returns void and swallows nothing but its
 * own decision — the actual send is queued. An order is placed whether or not the gateway is
 * reachable, which is Law 7 applied to a notification: `unjudged` must not block a sale.
 */
final class OrderNotifier
{
    public function __construct(private readonly OrderMessages $messages) {}

    public function confirmed(Order $order): void
    {
        $this->queue($order, $this->messages->confirmation($order), 'confirmation');
    }

    /**
     * The unpaid nudge.
     *
     * ⚠️ HER MANUAL ORDERS ONLY. A customer-placed order has a 30-minute payment window and
     * is, by definition, in front of somebody who is mid-checkout — texting them about a
     * payment screen they are looking at is noise, and noise is what makes people ignore the
     * message that matters.
     */
    public function paymentReminder(Order $order): void
    {
        if ($order->source === OrderSource::Online || $order->is_paid) {
            return;
        }

        $this->queue($order, $this->messages->paymentReminder($order), 'payment_reminder');
    }

    public function ready(Order $order): void
    {
        $this->queue($order, $this->messages->ready($order), 'ready');
    }

    /**
     * "Your food is tomorrow."
     *
     * ⚠️ NOT SENT FOR AN ORDER THAT IS ALREADY DONE OR CANCELLED. The command that drives
     * this filters on status too, but a reminder for a collected order is the single most
     * confusing message this system could send, so it is refused here as well — the check is
     * cheap and the failure is not.
     */
    public function collectionReminder(Order $order): void
    {
        if ($order->status->isTerminal()) {
            return;
        }

        $this->queue($order, $this->messages->collectionReminder($order), 'collection_reminder');
    }

    public function cancelled(Order $order): void
    {
        $this->queue($order, $this->messages->cancelled($order), 'cancelled');
    }

    /**
     * The one gate every message passes.
     *
     * `sms.enabled` is separate from the driver on purpose: "stop texting people right now"
     * should not require deciding which gateway you are not using.
     *
     * The number is re-normalised rather than trusted. Orders store E.164 already, but this
     * is the last point before an external system sees it, and a malformed number reaches
     * the gateway as a refusal that costs a queue job to discover.
     */
    private function queue(Order $order, string $message, string $context): void
    {
        if (! config('sms.enabled', true)) {
            return;
        }

        $to = GhanaPhone::normalise($order->contact_phone);

        if ($to === null) {
            return;
        }

        SendSms::dispatch($to, $message, "{$context}:{$order->order_number}");
    }
}
