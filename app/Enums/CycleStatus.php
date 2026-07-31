<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a cycle is in its own life. Distinct from whether ordering is open RIGHT NOW —
 * that is `OrderingState`, resolved by `CycleGate`, and it depends on the clock and the
 * override as well as this.
 */
enum CycleStatus: string
{
    /** Being planned. Invisible to customers however the dates read. */
    case Draft = 'draft';

    /**
     * Live. Whether it is actually taking orders depends on the clock and the override —
     * a published cycle whose window has not opened yet is `not_yet_open`, not closed.
     */
    case Published = 'published';

    /** She shut it by hand, or the window ended. Cooking may still be ahead. */
    case Closed = 'closed';

    /** Cooked and delivered. Read-only from here. */
    case Completed = 'completed';

    /** Out of the way. The only status exempt from the no-overlap constraint. */
    case Archived = 'archived';

    /** Draft cycles are invisible to customers no matter what their dates say. */
    public function isVisibleToCustomers(): bool
    {
        return in_array($this, [self::Published, self::Closed, self::Completed], true);
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Published, self::Closed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Closed => 'Closed',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }
}
