<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders — the centre of the system (brief §3.2).
 *
 * Every money column is an integer count of PESEWAS. 4000 is GHS 40.00. No floats, no
 * decimal strings, one conversion boundary, and the formatter is the only thing that ever
 * divides by 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCheckoutSessions();
        $this->createOrders();
        $this->createOrderItems();
        $this->createOrderStatusHistory();
        $this->createPayments();
    }

    /**
     * The basket, before it is an order.
     *
     * ⚠️ THE SESSION IS THE REAL PATH TO AN ORDER (brief §5.8, trap §10.9).
     *
     * The original put its stock gate on the direct order endpoint, which the till did not
     * use — the gate was live and inert at the same time, and a sale went through four
     * minutes after it deployed. Here every basket becomes an order through
     * `OrderCreator`, and both the customer confirm path and admin manual entry call it,
     * with a test per path.
     */
    private function createCheckoutSessions(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table): void {
            $table->id();

            // Random, unguessable. This is what the client holds, so it must not be a
            // sequence anyone can walk (same reasoning as the order tracking token).
            $table->string('token', 64)->unique();

            // Null for a guest, which is most of them. Every join must tolerate it.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_session', 64)->nullable()->index();

            // The lines, as JSON. A basket is not worth five tables — it is short-lived,
            // always read whole, and never queried by line.
            $table->jsonb('lines')->default('[]');

            $table->foreignId('order_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cycle_day_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('open'); // open | confirmed | expired

            // Abandoned baskets are swept rather than kept forever.
            $table->timestampTz('expires_at');

            $table->timestamps();
        });

        DB::statement("ALTER TABLE checkout_sessions ADD CONSTRAINT checkout_sessions_status_check CHECK (status IN ('open', 'confirmed', 'expired'))");
    }

    private function createOrders(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            /**
             * "A001" — per-branch letter plus a sequence. Human-readable and dictatable
             * over the phone, which is the actual requirement.
             *
             * ⚠️ AN IDENTIFIER, NOT A CREDENTIAL. It is guessable by construction, so it
             * never appears in a URL.
             */
            $table->string('order_number')->unique();

            /**
             * What URLs use instead (brief §5.6).
             *
             * Guest tracking has to stay unauthenticated — it is where the payment gateway
             * redirects someone with no account — so a sequential number in the URL would
             * let anyone walk it and read off the day's volume. The original throttles that
             * and calls it mitigation rather than a fix.
             */
            $table->string('tracking_token', 64)->unique();

            $table->foreignId('branch_id')->constrained();

            /** Snapshot, don't join. A rename must not rewrite last month's receipts. */
            $table->jsonb('branch_snapshot');

            // NULL for guests, and every customer join must tolerate it (trap §10.6).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->default('received');
            $table->string('order_type');   // pickup | delivery | shipping
            $table->string('source')->default('online');
            $table->boolean('is_manual_entry')->default(false);

            /**
             * Pre-order. A MEAL order is bound to a cycle day; a PANTRY-ONLY order is not
             * bound to anything, because shelf-stable goods ship whenever they are packed.
             * Hence all three nullable together — see the CHECK below.
             */
            $table->foreignId('order_cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cycle_day_id')->nullable()->constrained()->nullOnDelete();
            $table->date('fulfil_date')->nullable()->index();

            // ── Money, all integer pesewas ────────────────────────────────────
            $table->unsignedInteger('subtotal')->default(0);
            $table->unsignedInteger('service_charge')->default(0);
            $table->unsignedInteger('delivery_fee')->default(0);
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->string('promo_code')->nullable();

            /**
             * ⚠️ WHO KEEPS THE DELIVERY FEE (brief §5.3).
             *
             * She uses a third-party courier, so the fee is PASS-THROUGH: collected and
             * handed over. It is not income. Every analytics query excludes pass-through
             * fees, and getting this wrong overstates every revenue figure in the business.
             */
            $table->string('delivery_fee_collection')->default('third_party');
            $table->string('courier_name')->nullable();
            $table->string('courier_reference')->nullable();

            $table->string('payment_method')->default('mobile_money');
            $table->string('payment_status')->default('pending');
            $table->boolean('is_paid')->default(false);

            /**
             * Departure #6: an order SHE enters by phone holds its slot unpaid until this
             * moment. A scheduled job then frees the capacity and flags the order, so she
             * never cooks for a no-show she has forgotten about. Null on customer orders,
             * which must be paid to hold anything.
             */
            $table->timestampTz('slot_hold_expires_at')->nullable()->index();
            $table->boolean('hold_expired')->default(false);

            $table->string('contact_name');
            $table->string('contact_phone', 20)->index();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_area')->nullable();
            $table->string('gps_code')->nullable();
            $table->text('delivery_note')->nullable();

            /** Staff-only. Never rendered on a customer surface. */
            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Cancellation (brief §5.4) ─────────────────────────────────────
            $table->string('cancel_requested_by')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->text('cancel_request_reason')->nullable();

            /**
             * STORED, not re-derived. A rejected cancellation restores exactly this —
             * guessing where the order "probably was" is how one ends up back in `received`
             * after the food is already cooked.
             */
            $table->string('cancel_previous_status')->nullable();

            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'fulfil_date']);
        });

        // Enum guards. ⚠️ Widening any of these means updating the CHECK too — including
        // the one on order_status_history, which is the easy one to forget (trap §10.11).
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('received', 'accepted', 'preparing', 'ready', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'completed', 'cancel_requested', 'cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_type_check CHECK (order_type IN ('pickup', 'delivery', 'shipping'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_source_check CHECK (source IN ('online', 'phone', 'whatsapp'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending', 'completed', 'failed', 'refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_fee_collection_check CHECK (delivery_fee_collection IN ('self', 'third_party'))");

        /**
         * A meal order needs a cycle day; a shipping order must not have one.
         *
         * This is the invariant that the pantry-only cart bug violated on the frontend — a
         * jollof-base order reaching checkout with no date. Enforced here so no code path
         * can produce a meal order floating free of a cooking day.
         */
        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT orders_fulfilment_binding_check CHECK (
                (order_type = 'shipping' AND cycle_day_id IS NULL AND fulfil_date IS NULL)
                OR
                (order_type IN ('pickup', 'delivery') AND cycle_day_id IS NOT NULL AND fulfil_date IS NOT NULL)
            )
        ");
    }

    private function createOrderItems(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->foreignId('menu_item_id')->constrained();

            /**
             * ⚠️ LOAD-BEARING (brief §3.2, §12.2).
             *
             * v2 recipes key off the option id. Keeping it now — even with IMS deferred —
             * is what stops v2 needing a migration sweep across every historical order.
             * Not cascading: an option must never be deletable out from under a receipt.
             */
            $table->foreignId('menu_item_option_id')->constrained('menu_item_options');

            /** Snapshot of what the customer saw. Never joined live. */
            $table->string('name');
            $table->unsignedInteger('unit_price');   // pesewas, at time of order
            $table->string('size_label')->nullable();
            $table->string('variant_key')->nullable();
            $table->string('category')->nullable();

            $table->unsignedInteger('quantity');
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    private function createOrderStatusHistory(): void
    {
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status')->nullable();   // null on the first row
            $table->string('to_status');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();    // snapshot; staff leave
            $table->text('note')->nullable();

            $table->timestampTz('created_at');
        });

        // ⚠️ Kept in sync with the orders enum ABOVE, `cancel_requested` included. The
        // original forgot this table when it widened the enum (trap §10.11).
        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_to_check CHECK (to_status IN ('received', 'accepted', 'preparing', 'ready', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'completed', 'cancel_requested', 'cancelled'))");
    }

    private function createPayments(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('provider')->default('paystack');

            /**
             * ⚠️ THE IDEMPOTENCY KEY. UNIQUE, and that is the whole defence.
             *
             * Paystack retries webhooks. Assume duplicates. With a unique index a replay is
             * a no-op at the database level rather than a second credit — which is not
             * something to leave to an `if` in a controller that two concurrent workers can
             * both pass (brief §5.7).
             */
            $table->string('reference')->unique();

            $table->unsignedInteger('amount');   // pesewas
            $table->string('currency', 3)->default('GHS');
            $table->string('status')->default('pending');
            $table->string('channel')->nullable();   // mobile_money, card, …

            /**
             * What the gateway actually kept. Gross is NOT revenue, and this is the column
             * that makes the difference visible (see the money dashboard in M6).
             */
            $table->unsignedInteger('fee')->nullable();
            $table->unsignedInteger('settled_amount')->nullable();
            $table->timestampTz('settled_at')->nullable();

            /** The raw callback, kept whole. A dispute is unarguable without it. */
            $table->jsonb('payload')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'completed', 'failed', 'abandoned', 'refunded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkout_sessions');
    }
};
