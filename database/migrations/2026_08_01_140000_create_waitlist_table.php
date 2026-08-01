<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Text me if a portion comes back."
     *
     * ⚠️ THE ONLY PLACE THIS SYSTEM CAPTURES DEMAND IT COULD NOT MEET. Every other table
     * records what was sold; a sold-out day otherwise leaves no trace of the eleven people
     * who wanted it, which is precisely the number that should be setting next week's
     * capacity.
     */
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cycle_day_id')->constrained()->cascadeOnDelete();

            /**
             * ⚠️ NULL MEANS "ANYTHING THAT DAY", NOT "NOTHING".
             *
             * Both are real requests and they behave differently: somebody waiting on waakye
             * specifically must not be texted when the etor comes back. A null here is
             * matched by any dish, and the notifier treats the two cases apart.
             */
            $table->foreignId('menu_item_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('phone', 20);
            $table->unsignedSmallInteger('quantity')->default(1);

            // Nullable — most people joining a waitlist have no account (trap §10.6).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /** waiting | notified | converted | expired */
            $table->string('status')->default('waiting');

            $table->timestampTz('notified_at')->nullable();

            /**
             * The order they eventually placed, if they did.
             *
             * ⚠️ THE ONLY WAY TO KNOW WHETHER THIS FEATURE WORKS. "We texted 40 people" is
             * not a result; "9 of them ordered" is, and it is the difference between keeping
             * the waitlist and quietly dropping it.
             */
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            /**
             * ⚠️ ONE ENTRY PER NUMBER PER DISH PER DAY.
             *
             * Somebody who taps the button three times must not be texted three times —
             * and a limit enforced only in the controller is a limit that a retried request
             * defeats. Postgres treats NULLs as distinct in a unique index, so a null
             * `menu_item_id` would not be deduped; `NULLS NOT DISTINCT` is what makes the
             * "anything that day" case behave like every other one.
             */
            $table->unique(['cycle_day_id', 'menu_item_id', 'phone'], 'waitlist_unique_request');

            $table->index(['status', 'cycle_day_id']);
        });

        // Laravel's schema builder has no flag for this, and without it the whole point of
        // the index above is lost for exactly the rows most likely to be duplicated.
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE waitlist_entries DROP CONSTRAINT waitlist_unique_request'
        );
        \Illuminate\Support\Facades\DB::statement(
            'ALTER TABLE waitlist_entries ADD CONSTRAINT waitlist_unique_request
             UNIQUE NULLS NOT DISTINCT (cycle_day_id, menu_item_id, phone)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
