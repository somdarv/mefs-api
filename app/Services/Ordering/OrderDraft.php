<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Models\User;

/**
 * Everything `OrderCreator` needs, and nothing it should not be told.
 *
 * ⚠️ THERE IS NO `branch_id` AND NO MONEY HERE. Both are derived server-side (brief Law 2,
 * Law 6): the branch from the principal, the totals from the catalogue and
 * `system_settings`. A field on this object is a field a caller can lie about, so the
 * object only carries facts the caller is the authority on — who they are, what they want,
 * and where it is going.
 *
 * `$actor` is the staff member entering the order by hand, or null for a customer. It is
 * what makes the two paths distinguishable *without* making them two code paths.
 */
final readonly class OrderDraft
{
    /**
     * @param  list<BasketLine>  $lines
     */
    public function __construct(
        public array $lines,
        public OrderType $type,
        public OrderSource $source,
        public string $contactName,
        public string $contactPhone,
        /** Required for pickup and delivery, forbidden for shipping. Checked in the creator. */
        public ?int $cycleDayId = null,
        public ?int $customerId = null,
        public ?string $deliveryAddress = null,
        public ?string $deliveryArea = null,
        public ?string $gpsCode = null,
        public ?string $deliveryNote = null,
        /** Staff-only, and only ever set on a manual entry. Never rendered to a customer. */
        public ?string $internalNotes = null,
        /**
         * How they intend to pay. A statement of intent, not of fact — `is_paid` moves only
         * when a payment lands, and a browser redirect is never proof of one.
         */
        public string $paymentMethod = 'mobile_money',
        public ?User $actor = null,
    ) {}
}
