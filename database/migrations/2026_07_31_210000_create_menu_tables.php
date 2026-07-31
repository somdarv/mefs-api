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
        /**
         * ONE ROW PER DISH. `UNIQUE(slug)`, no `branch_id`.
         *
         * The brief calls this the single most important modelling decision in the system
         * (§3.3), and the original got it wrong first: `menu_items.branch_id` with
         * `UNIQUE(branch_id, slug)` makes the same dish a different row at every branch.
         *
         * The cost was not tidiness. Everything downstream keys off menu-item and option
         * ids, so opening a second branch **silently stopped recipe deduction entirely** —
         * the second branch's options matched no recipe and the deduction service hit
         * `continue` on every sale without an error. Promos landed at one branch only.
         * Ratings reset per branch.
         *
         * Branch service is the `menu_item_branches` pivot below.
         */
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Relative path on the public disk. Null renders the branded fallback card —
            // she will not have photography for everything on day one.
            $table->string('image_path')->nullable();

            $table->string('category'); // meal | pantry — CHECK below

            /**
             * The weekly rotation, as a TEMPLATE.
             *
             * This is not what a customer sees. It pre-fills the dish matrix when a new
             * cycle is created (M3), and `cycle_day_items` is the per-date truth she can
             * then edit freely — Waakye on a Thursday because someone asked.
             *
             * Empty means day-independent: the pantry line is shelf-stable and sells
             * whenever, so it belongs to no rotation slot.
             */
            $table->jsonb('default_service_weekdays')->default('[]');

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_category_check CHECK (category IN ('meal', 'pantry'))");

        /**
         * OPTIONS ARE THE SELLABLE UNIT (brief §6).
         *
         * A dish always has at least one option, even when the customer perceives no
         * choice, because the order line references the option and v2 recipes attach to it.
         * A dish with no option row can never be costed or deducted.
         *
         * ⚠️ A SOFT-DELETED OPTION STILL OCCUPIES ITS UNIQUE INDEX (brief trap §10.5).
         * Re-adding `500ml-mild` after deleting it fails on the unique constraint unless the
         * write resolves with `withTrashed()->firstOrNew()`. See MenuOption::reinstate().
         */
        Schema::create('menu_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();

            // Stable key, unique per item. Survives renames — the label may change, this
            // may not, because order history and (in v2) recipes point at it.
            $table->string('option_key');

            $table->string('label');
            $table->string('size_label')->nullable();   // "500ml", null for a plated meal
            $table->string('variant_key')->nullable();  // "mild" | "spicy", null if no axis

            // Integer MINOR UNITS — pesewas. 4000 = GHS 40.00. No floats, no decimal
            // strings, one conversion boundary, and the formatter is the only thing that
            // ever divides by 100.
            $table->unsignedInteger('price');

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['menu_item_id', 'option_key']);
        });

        /**
         * One kitchen today. The pivot exists anyway — see the brief quote above.
         *
         * Two rules on it (brief §3.3):
         *  - Availability sync is INSERT-ONLY. A "sold out today" must never be switched
         *    back on by a deploy or a sync command.
         *  - Authority split: availability is an operational call, price is an ownership
         *    call. Enforced by separate permissions (menu.manage vs menu.price).
         */
        Schema::create('menu_item_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['menu_item_id', 'branch_id']);
        });

        /** Priced extras. Deliberately NOT stock-deducted, matching the original (§7.4). */
        Schema::create('menu_add_ons', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('price'); // minor units
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('menu_item_menu_add_on', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_add_on_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['menu_item_id', 'menu_add_on_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_menu_add_on');
        Schema::dropIfExists('menu_add_ons');
        Schema::dropIfExists('menu_item_branches');
        Schema::dropIfExists('menu_item_options');
        Schema::dropIfExists('menu_items');
    }
};
