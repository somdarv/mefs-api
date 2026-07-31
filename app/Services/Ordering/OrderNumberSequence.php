<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * "A001" — the number she reads down the phone.
 *
 * ⚠️ AN IDENTIFIER, NOT A CREDENTIAL. It is guessable by construction, which is exactly why
 * it never appears in a URL; `tracking_token` does that job (brief §5.6).
 *
 * ⚠️ AND IT IS GENERATED UNDER A LOCK. Two orders placed in the same second must not both
 * read "the last one was A007". The branch row is locked for the duration, so the read and
 * the write cannot interleave — and the unique index on `order_number` is the backstop if
 * anyone ever calls this outside a transaction.
 *
 * Must be called inside a transaction; `lockForUpdate` outside one releases immediately and
 * guards nothing.
 */
final class OrderNumberSequence
{
    /** Zero-padded to three, then as wide as it needs to be. A999 is followed by A1000. */
    private const PAD = 3;

    public function next(Branch $branch): string
    {
        $prefix = $branch->order_number_prefix ?: 'A';

        // Take the lock on the branch row. Everything below reads a stable world.
        DB::table('branches')->where('id', $branch->id)->lockForUpdate()->first();

        $last = Order::query()
            ->where('branch_id', $branch->id)
            ->where('order_number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('order_number');

        $next = $last === null
            ? 1
            : ((int) preg_replace('/\D/', '', $last)) + 1;

        return $prefix.str_pad((string) $next, self::PAD, '0', STR_PAD_LEFT);
    }
}
