<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a customer may order for a given date right now.
 *
 * Four states rather than a boolean, because "closed", "not open yet" and "sold out" want
 * different sentences and lead the customer to different actions — wait, come back later,
 * or pick another day.
 *
 * Mirrored in ../mefs/src/lib/preorder/ordering-state.ts for DISPLAY only. The server
 * decides; the client explains.
 */
enum OrderingStatus: string
{
    case Open = 'open';

    /** Published, but the ordering window has not started. Tell them when it does. */
    case NotYetOpen = 'not_yet_open';

    /** Window over, day shut, or she closed it by hand. */
    case Closed = 'closed';

    /** Capacity reached. Different from closed: another day may still be available. */
    case SoldOut = 'sold_out';
}
