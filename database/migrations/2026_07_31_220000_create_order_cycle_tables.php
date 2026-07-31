<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE ORDER CYCLE — the unit of planning for this business.
 *
 * One cooking window, plus a SEPARATE and earlier ordering window. "Taking orders 1-4 Aug,
 * cooking 5-12 Aug" is one cycle.
 *
 * This replaces the rolling weekday+cutoff model the storefront shipped with. A rolling rule
 * cannot express "we take orders for one week, then cook it"; a cycle can express a rolling
 * rule, so the cycle model is strictly more general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cycles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                    // "Week of 5 Aug"
            $table->string('slug')->unique();

            // When she cooks.
            $table->date('service_start_date');
            $table->date('service_end_date');

            // When customers may order. Deliberately a TIMESTAMP, not a date: "closes 6pm
            // Monday" is the actual business rule, and a date column cannot say that.
            $table->timestampTz('orders_open_at');
            $table->timestampTz('orders_close_at');

            $table->string('status')->default('draft');

            /**
             * ⚠️ ONE NULLABLE COLUMN, NOT TWO BOOLEANS.
             *
             * `is_force_open` + `is_force_closed` can both be true, and then nobody — not
             * the code, not her — knows what the shop is doing. One column makes the states
             * mutually exclusive by construction.
             *
             * `force_open` beating the cutoff check IS the "reopen orders past the deadline"
             * feature. It is not a workaround.
             */
            $table->string('override')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('override_at')->nullable();

            // Whole-cycle cap. Null means uncapped; per-day and per-dish caps still apply.
            $table->unsignedInteger('order_capacity')->nullable();

            // Customer-visible: "Travelling, no cooking the week of the 19th".
            $table->text('note')->nullable();

            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        DB::statement("ALTER TABLE order_cycles ADD CONSTRAINT order_cycles_status_check CHECK (status IN ('draft', 'published', 'closed', 'completed', 'archived'))");
        DB::statement("ALTER TABLE order_cycles ADD CONSTRAINT order_cycles_override_check CHECK (override IS NULL OR override IN ('force_open', 'force_closed'))");

        // A cycle that ends before it starts, or closes before it opens, is nonsense that
        // would make every date calculation downstream quietly wrong.
        DB::statement('ALTER TABLE order_cycles ADD CONSTRAINT order_cycles_service_window_check CHECK (service_end_date >= service_start_date)');
        DB::statement('ALTER TABLE order_cycles ADD CONSTRAINT order_cycles_order_window_check CHECK (orders_close_at > orders_open_at)');

        /**
         * ⚠️ NO TWO LIVE CYCLES MAY COVER THE SAME DATE.
         *
         * Without this, "which cycle owns 6 August?" has two answers, and every query that
         * resolves a date to a cycle silently picks whichever row came back first — a bug
         * that surfaces as one customer's order landing in last week's plan.
         *
         * An EXCLUSION constraint rather than application validation, because the check has
         * to hold under concurrency: two admins creating overlapping cycles at the same
         * moment both pass a SELECT-then-INSERT check.
         *
         * Archived cycles are exempt — history is allowed to overlap the present.
         */
        DB::statement("
            ALTER TABLE order_cycles ADD CONSTRAINT order_cycles_no_overlapping_service_window
            EXCLUDE USING gist (daterange(service_start_date, service_end_date, '[]') WITH &&)
            WHERE (status <> 'archived')
        ");

        /**
         * One row per date in the service window.
         *
         * She can close a single day without touching the cycle — "not cooking Wednesday,
         * I have a funeral" — and cap or re-cutoff each day individually.
         */
        Schema::create('cycle_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_cycle_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->boolean('is_open')->default(true);

            // Per-day override of the cycle's close. Null means "use the cycle's".
            $table->timestampTz('cutoff_at')->nullable();

            // Orders for this day. Null means uncapped.
            $table->unsignedInteger('capacity')->nullable();

            // Staff-only. Never rendered on a customer surface.
            $table->text('kitchen_note')->nullable();

            $table->timestamps();

            $table->unique(['order_cycle_id', 'date']);
            $table->index('date');
        });

        /**
         * ⚠️ THE DISH MATRIX — "which dish goes on which day".
         *
         * This is the per-date TRUTH. `menu_items.default_service_weekdays` is only a
         * template used to pre-fill these rows when a cycle is created; after that she edits
         * freely, and putting Waakye on a Thursday must not require editing the template.
         */
        Schema::create('cycle_day_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_available')->default(true);

            // Portions of THIS dish on THIS day. Null means uncapped. This is how "only 20
            // goat jollof on Thursday" works without needing full inventory.
            $table->unsignedInteger('portion_capacity')->nullable();

            // A projection, recomputed from paid order lines — never the source of truth.
            // Kept as a column so the matrix can render without an aggregate per cell.
            $table->unsignedInteger('portions_sold')->default(0);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['cycle_day_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_day_items');
        Schema::dropIfExists('cycle_days');
        Schema::dropIfExists('order_cycles');
    }
};
