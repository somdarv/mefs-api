<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer pays without leaving the shop.
 *
 * Paystack's hosted checkout is gone: a charge is pushed straight to the handset and the
 * customer approves it there. Three columns, all on the ATTEMPT rather than the order —
 * a second attempt may name a different wallet, and both facts matter at month end.
 *
 * ⚠️ `momo_phone` IS NOT `orders.contact_phone`. One is who to ring when the food is ready,
 * the other is whose money moves, and they diverge often enough that sharing a column would
 * silently re-point the collection text every time somebody retried on another wallet. See
 * `App\Services\Payments\MomoInstruction`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // E.164, like every other number stored here.
            $table->string('momo_phone', 20)->nullable()->after('channel');

            // Paystack's own code — `mtn` / `vod` / `atl`. Not a label; `MomoNetwork` turns
            // it into one, and `vod` still means Telecel because Paystack never renamed it.
            $table->string('momo_network', 8)->nullable()->after('momo_phone');

            /*
             * When we stop believing the prompt will be answered.
             *
             * Nothing sweeps this — a stale pending attempt is harmless, because the ORDER
             * stays pending either way and she can still ring the customer. It is here so the
             * "this prompt has expired, try again" moment is a server fact with a timestamp
             * rather than a magic number counted down inside a React component.
             */
            $table->timestamp('prompt_expires_at')->nullable()->after('momo_network');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['momo_phone', 'momo_network', 'prompt_expires_at']);
        });
    }
};
