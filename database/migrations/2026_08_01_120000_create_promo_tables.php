<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createPromos();
        $this->createRedemptions();
        $this->linkOrders();
        $this->linkCheckoutSessions();
    }

    /**
     * A discount code.
     *
     * ⚠️ EVERY LIMIT HERE IS NULLABLE, AND NULL MEANS "NO LIMIT" — NOT ZERO.
     *
     * `usage_limit: 0` and `usage_limit: null` are opposite instructions, and a column that
     * defaulted to 0 would make every promo created without one immediately unusable. The
     * same reasoning as `portion_capacity` on `cycle_day_items`: an absent cap is not a cap
     * of nothing.
     */
    private function createPromos(): void
    {
        Schema::create('promos', function (Blueprint $table): void {
            $table->id();

            /**
             * ⚠️ STORED UPPERCASE, MATCHED EXACTLY.
             *
             * Case-insensitive matching via `ILIKE` would make the unique index useless —
             * "summer" and "SUMMER" would be two rows both claiming the same code, and
             * which one applied would depend on insertion order. Normalising on write means
             * the index is the guarantee. The customer may type either.
             */
            $table->string('code', 32)->unique();

            /** Staff-facing. What this code is for, so she can tell two apart in a list. */
            $table->string('description')->nullable();

            $table->string('type');            // percentage | fixed
            $table->unsignedInteger('value');  // percent (1-100), or pesewas

            /**
             * The cap on a percentage discount, in pesewas.
             *
             * "20% off" on a ₵400 pantry order is ₵80 she did not intend to give away. Null
             * for a fixed discount, where it would mean nothing.
             */
            $table->unsignedInteger('max_discount')->nullable();

            /** Minimum spend, in pesewas, measured against the discountable subtotal. */
            $table->unsignedInteger('min_subtotal')->nullable();

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            /**
             * ⚠️ `times_used` IS INCREMENTED UNDER THE ORDER'S TRANSACTION, NOT ON A READ.
             *
             * Checking a limit and then writing it without a lock is the same check-then-act
             * race the portion ledger exists to prevent: two customers redeem the last use
             * of a one-shot code at the same instant, both read 0, both pass. The redemption
             * row and this counter move together inside `OrderCreator`'s transaction.
             */
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('times_used')->default(0);

            /**
             * Which lines the discount is calculated against: `all`, `meals`, `pantry`.
             *
             * A jollof-base launch code should not quietly discount a ₵200 catering order,
             * and "20% off the pantry range" has to mean the pantry lines rather than the
             * basket that happens to contain one.
             */
            $table->string('scope')->default('all');

            /** First order only — checked by phone, because most customers are guests. */
            $table->boolean('first_order_only')->default(false);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        /*
         * ⚠️ THE RULES THAT MUST NOT DEPEND ON APPLICATION CODE.
         *
         * A percentage over 100 is a promo that pays the customer to order. A fixed discount
         * of zero is a code that silently does nothing and generates a support conversation
         * every time it is used. Both are refused by the validator too — this is the layer
         * that survives a seeder, a tinker session, and a future endpoint nobody remembered
         * to guard.
         */
        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_value_check CHECK (
                (type = 'percentage' AND value > 0 AND value <= 100)
                OR
                (type = 'fixed' AND value > 0)
            )
        ");

        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_window_check CHECK (
                starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at
            )
        ");

        // A cap on a fixed discount is meaningless, and storing one invites code that reads
        // it and quietly halves somebody's ₵20 off.
        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_max_discount_check CHECK (
                max_discount IS NULL OR type = 'percentage'
            )
        ");
    }

    /**
     * One row per redemption. The evidence behind `times_used`.
     *
     * The counter alone cannot answer "has *this* customer used it before", and it cannot be
     * rebuilt if it ever drifts. These rows can.
     */
    private function createRedemptions(): void
    {
        Schema::create('promo_redemptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('promo_id')->constrained()->cascadeOnDelete();

            /**
             * ⚠️ ONE REDEMPTION PER ORDER, ENFORCED HERE.
             *
             * The per-customer limit is only as good as the impossibility of writing the
             * same order twice, and a retried request is the ordinary way that happens.
             */
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            // Nullable: most customers are guests and have no customer row (trap §10.6).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /**
             * ⚠️ THE PER-CUSTOMER LIMIT IS COUNTED ON THIS, NOT ON `customer_id`.
             *
             * A guest ordering three times is three orders with `customer_id` null, and a
             * limit counted on the customer row would let every one of them through. The
             * phone is the identity the business actually uses — it is what she calls, and
             * it is what a returning customer types in again.
             */
            $table->string('phone', 20)->index();

            /** What it actually took off, in pesewas. Never recomputed from the promo. */
            $table->unsignedInteger('discount');

            $table->timestamps();

            $table->index(['promo_id', 'phone']);
        });
    }

    private function linkOrders(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            /*
             * `promo_code` is already on the order and stays: it is the SNAPSHOT, the code
             * as the customer typed it, and it must survive the promo being renamed or
             * deleted exactly as item names survive a menu edit. This id is for joining
             * from the admin side — "which orders used SUMMER" — and it nulls on delete
             * while the snapshot does not.
             */
            $table->foreignId('promo_id')->nullable()->after('promo_code')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * The code lives on the basket, not only on the confirm request.
     *
     * ⚠️ SO THE QUOTE AND THE ORDER ASK THE SAME QUESTION. If the code were sent only at
     * confirm, the checkout screen would have to hold it in client state and the quoted
     * total would be computed from something the server never saw — which is precisely how
     * a screen ends up showing ₵10 off while the charge is ₵0 off.
     *
     * ⚠️ AND ONLY THE CODE, NEVER A DISCOUNT. There is deliberately no `discount` column
     * here. A stored amount would be a number the client could get written and the confirm
     * path could be tempted to trust.
     */
    private function linkCheckoutSessions(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->string('promo_code', 32)->nullable()->after('lines');
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table): void {
            $table->dropColumn('promo_code');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promo_id');
        });

        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promos');
    }
};
