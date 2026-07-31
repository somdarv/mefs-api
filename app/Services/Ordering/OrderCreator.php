<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\CycleDay;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Support\GhanaPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⚠️⚠️ THE ONLY PATH FROM A BASKET TO AN ORDER. ⚠️⚠️
 *
 * This is the trap the brief spends §5.8 and §10.9 on, and it is the single most expensive
 * mistake in the original: the stock gate went on the direct order endpoint, which the till
 * did not use. The gate was live and inert at the same time — it looked deployed, it passed
 * review, and a sale went through for 23 portions against a balance of 6 **four minutes
 * after it shipped**.
 *
 * So there is one service. Customer checkout confirm calls it. Admin manual entry calls it.
 * `OrderCreatorTest` has a test per path, asserting the same refusal from both. If a second
 * creation route ever appears, this comment is the argument against it.
 *
 * ── WHAT THIS OWNS ────────────────────────────────────────────────────────────
 *
 *   the gate      `CycleGate::check()` for the day, `checkItem()` for each dish
 *   the money     looked up from the catalogue and `system_settings` — never sent
 *   the branch    derived server-side, never from the request (brief Law 2)
 *   the numbers   `order_number` under a lock, `tracking_token` random
 *   the ledger    `portions_sold`, moved once, inside the same transaction
 *   the audit     the first `order_status_history` row
 *
 * ── WHAT THE ADMIN PATH DOES *NOT* GET ────────────────────────────────────────
 *
 * A way past the gate. She reopens a closed week with `force_open` on the cycle, which is
 * permissioned and logged with a reason — not by a flag on an order that leaves no trace of
 * why the kitchen took a job it had said no to. The two paths differ in exactly two ways:
 * a manual order records its source and may hold its slot unpaid for longer.
 */
final class OrderCreator
{
    public function __construct(
        private readonly CycleGate $gate,
        private readonly BasketPricer $pricer,
        private readonly PriceCalculator $prices,
        private readonly PortionLedger $ledger,
        private readonly OrderNumberSequence $numbers,
        private readonly OrderStatusMachine $statuses,
    ) {}

    /**
     * @throws OrderRefused
     */
    public function create(OrderDraft $draft): Order
    {
        if ($draft->lines === []) {
            throw OrderRefused::emptyBasket();
        }

        $phone = GhanaPhone::normalise($draft->contactPhone)
            ?? throw OrderRefused::invalidContact('Enter a Ghanaian mobile number, like 024 123 4567.');

        if (trim($draft->contactName) === '') {
            throw OrderRefused::invalidContact('An order needs a name to call out.');
        }

        if ($draft->type !== OrderType::Pickup && ! $this->hasDestination($draft)) {
            throw OrderRefused::invalidContact('A delivery needs an address.');
        }

        $priced = $this->pricer->price($draft->lines);
        $branch = $this->resolveBranch();

        // ── The lock, and everything that has to happen under it ──────────────
        //
        // Check-then-insert is only safe if nothing can slip between the two. The day row
        // is locked for the whole transaction, so two customers confirming the last portion
        // at the same instant serialise: the second one re-reads a `portions_sold` that
        // already includes the first, and the gate refuses it. Without the lock they both
        // read 5, both pass, and the kitchen owes six portions from a pot of five.
        return DB::transaction(function () use ($draft, $priced, $branch, $phone): Order {
            $day = $draft->type->requiresCycleDay()
                ? $this->lockDay($draft)
                : $this->refuseCycleDayOnShipping($draft);

            if ($day !== null) {
                $this->assertOrderable($day, $priced);
            } else {
                $this->assertPantryOnly($priced);
            }

            $totals = $this->prices->calculate($priced, $draft->type);

            $order = new Order;

            $order->order_number = $this->numbers->next($branch);
            $order->tracking_token = Str::random(48);

            $order->branch_id = $branch->id;
            // Snapshot, don't join. A rename must not rewrite last month's receipts.
            $order->branch_snapshot = $branch->toOrderSnapshot();

            $order->customer_id = $draft->customerId;
            $order->status = OrderStatus::Received;
            $order->order_type = $draft->type;
            $order->source = $draft->source;
            $order->is_manual_entry = $draft->source->isManualEntry();

            $order->order_cycle_id = $day?->order_cycle_id;
            $order->cycle_day_id = $day?->id;
            $order->fulfil_date = $day?->date;

            $order->forceFill($totals->toArray());

            $order->payment_method = $draft->paymentMethod;
            $order->payment_status = 'pending';
            $order->is_paid = false;
            $order->slot_hold_expires_at = $this->holdExpiry($draft);

            $order->contact_name = trim($draft->contactName);
            $order->contact_phone = $phone;
            $order->delivery_address = $draft->deliveryAddress;
            $order->delivery_area = $draft->deliveryArea;
            $order->gps_code = $draft->gpsCode;
            $order->delivery_note = $draft->deliveryNote;
            $order->internal_notes = $draft->internalNotes;

            $order->created_by = $draft->actor?->id;
            $order->placed_at = now();

            $order->save();

            foreach ($priced as $line) {
                $order->items()->create($line->toOrderItemAttributes());
            }

            if ($day !== null) {
                $this->ledger->reserve($day, $priced);
            }

            $this->statuses->recordPlacement(
                $order,
                $draft->actor,
                $draft->source->isManualEntry() ? 'Entered by hand ('.$draft->source->label().')' : null,
            );

            return $order->load('items');
        });
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    /**
     * Both halves of the verdict: the day, then each dish on it.
     *
     * @param  list<PricedLine>  $priced
     *
     * @throws OrderRefused
     */
    private function assertOrderable(CycleDay $day, array $priced): void
    {
        $cycle = $day->cycle;

        $state = $this->gate->check($cycle, $day);

        if (! $state->allowsOrdering()) {
            throw OrderRefused::byGate($state);
        }

        // ⚠️ SUMMED PER DISH, NOT CHECKED PER LINE. A basket holding a standard Etor and a
        // plain Etor is two lines out of one pot; checking them separately passes twice
        // against the same six remaining portions and oversells by exactly the amount
        // nobody notices until service.
        $wanted = $this->ledger->mealQuantities($priced);

        foreach ($wanted as $menuItemId => $quantity) {
            $itemState = $this->gate->checkItem($cycle, $day, $menuItemId, $quantity);

            if (! $itemState->allowsOrdering()) {
                $optionId = $this->firstOptionFor($priced, $menuItemId);

                throw OrderRefused::byGate($itemState, $optionId);
            }
        }
    }

    /**
     * A shipping order may hold pantry goods and nothing else.
     *
     * `orders_fulfilment_binding_check` already refuses a shipping order with a cycle day,
     * so the *binding* is safe at the database. What it cannot see is a meal smuggled into
     * an undated shipment — waakye posted to Tamale, cooked on no particular day. That is
     * this check.
     *
     * @param  list<PricedLine>  $priced
     *
     * @throws OrderRefused
     */
    private function assertPantryOnly(array $priced): void
    {
        foreach ($priced as $line) {
            if ($line->isDateBound()) {
                throw OrderRefused::fulfilmentMismatch(
                    "{$line->name} is cooked fresh and has to be collected or delivered on a cooking day."
                );
            }
        }
    }

    /**
     * @throws OrderRefused
     */
    private function lockDay(OrderDraft $draft): CycleDay
    {
        if ($draft->cycleDayId === null) {
            throw OrderRefused::fulfilmentMismatch('Pick a day to collect or receive this order.');
        }

        $day = CycleDay::query()->lockForUpdate()->find($draft->cycleDayId);

        if ($day === null) {
            throw OrderRefused::fulfilmentMismatch('That day is no longer on the calendar.');
        }

        // Loaded after the lock so the gate reads the state the lock is protecting.
        $day->load(['cycle', 'items']);

        return $day;
    }

    /**
     * @throws OrderRefused
     */
    private function refuseCycleDayOnShipping(OrderDraft $draft): ?CycleDay
    {
        if ($draft->cycleDayId !== null) {
            throw OrderRefused::fulfilmentMismatch('A shipped order is not tied to a cooking day.');
        }

        if (SystemSetting::get('pantry_shipping_enabled', true) !== true) {
            throw OrderRefused::fulfilmentMismatch('Shipping is switched off at the moment.');
        }

        return null;
    }

    // ── Derived server-side ───────────────────────────────────────────────────

    /**
     * ⚠️ NEVER FROM THE REQUEST (brief Law 2).
     *
     * One kitchen today, so this is one row. When there are more it resolves from the
     * authenticated principal — and the shape of the call site does not change, which is
     * the point of putting it behind a method now.
     *
     * @throws OrderRefused
     */
    private function resolveBranch(): Branch
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first() ?? throw OrderRefused::noBranch();
    }

    /**
     * When this order stops holding its slot if nobody pays.
     *
     * Departure #6 says an order SHE enters may hold a slot unpaid and a customer-placed
     * one may not. Both get an expiry here, and the difference is its length and its
     * meaning:
     *
     *  - **manual** — `manual_order_hold_minutes` (2h by default). A deliberate hold: her
     *    regulars pay by MoMo transfer after the call.
     *  - **online** — `online_payment_window_minutes` (30m). Not a hold at all but the
     *    opposite: the window in which the customer must actually pay. It exists so the
     *    seat is not occupied by an abandoned Paystack tab, which is the mechanism by which
     *    "a customer-placed order that is unpaid never holds a slot" is true rather than
     *    merely stated.
     *
     * Either way capacity comes back on its own, which is the requirement.
     */
    private function holdExpiry(OrderDraft $draft): Carbon
    {
        $minutes = $draft->source->mayHoldUnpaid()
            ? (int) SystemSetting::get('manual_order_hold_minutes', 120)
            : (int) SystemSetting::get('online_payment_window_minutes', 30);

        return now()->addMinutes(max(1, $minutes));
    }

    private function hasDestination(OrderDraft $draft): bool
    {
        return trim((string) $draft->deliveryAddress) !== '';
    }

    /** @param list<PricedLine> $priced */
    private function firstOptionFor(array $priced, int $menuItemId): ?int
    {
        foreach ($priced as $line) {
            if ($line->menuItemId === $menuItemId) {
                return $line->option->id;
            }
        }

        return null;
    }
}
