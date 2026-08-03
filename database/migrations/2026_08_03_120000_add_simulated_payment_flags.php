<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fake money must never be indistinguishable from real money.
 *
 * ── WHY THIS IS TWO COLUMNS AND NOT ONE ───────────────────────────────────────
 *
 * `payments.is_simulated` records that a particular ATTEMPT was settled without a gateway.
 * That is the audit fact: a row in the payments table saying ₵120 arrived, which no bank
 * statement will ever corroborate, has to say so about itself.
 *
 * `orders.is_simulated` is the one every money query actually reads. `Money\Insights` sums
 * `orders`, not `payments` — so without a flag on the order, a simulated payment marking
 * `is_paid = true` would put invented money straight into her takings, in the same column as
 * real takings, with nothing on screen to distinguish them. It is set the moment a simulated
 * payment is BEGUN rather than when one completes, so an abandoned test order does not
 * silently inflate the "unpaid" figure either.
 *
 * Both default false, so every row that exists today is real, which it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->boolean('is_simulated')->default(false)->after('provider');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_simulated')->default(false)->after('is_paid');

            // Every revenue query filters on this, and it is low-cardinality against a table
            // that only ever holds a handful of true rows — exactly the shape a partial
            // index is for. Postgres can then answer "the real orders" without a seq scan.
            $table->index('is_simulated');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('is_simulated');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['is_simulated']);
            $table->dropColumn('is_simulated');
        });
    }
};
