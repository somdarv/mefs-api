<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Her hand on the switch.
 *
 * ⚠️ These are two cases of ONE nullable column, never two booleans. `is_force_open` and
 * `is_force_closed` can both be true, and then the shop's state is genuinely undefined —
 * whichever check runs first wins, and that ordering is an implementation detail nobody
 * should have to know.
 */
enum CycleOverride: string
{
    /**
     * Take orders even though the window says no.
     *
     * This is the "reopen orders past the deadline" feature, and it is the whole reason the
     * override column exists. Someone rings at 9pm on the Monday after close; she says yes.
     */
    case ForceOpen = 'force_open';

    /**
     * Stop taking orders even though the window says yes. Fully booked, or she is ill.
     * Beats `ForceOpen` by precedence, because a stop is always the safer verdict.
     */
    case ForceClosed = 'force_closed';

    public function label(): string
    {
        return match ($this) {
            self::ForceOpen => 'Forced open',
            self::ForceClosed => 'Forced closed',
        };
    }
}
