<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * One kitchen at launch — and the table exists anyway.
         *
         * The brief is emphatic (§3.3, departure #5): retrofitting branch scoping is what
         * silently killed recipe deduction in the original, because everything downstream
         * keys off menu-item and option ids and the second branch's options matched no
         * recipe. One row costs nothing now; the retrofit costs a migration sweep across
         * every historical order.
         */
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Snapshotted onto every order. A rename must not rewrite old receipts.
            $table->string('address');
            $table->string('phone', 20);

            // The order-number prefix: `A001`, `A002`, … `B001` at a second branch.
            // Human-readable and dictatable over the phone — an identifier, NOT a
            // credential. URLs use the per-order tracking token instead (brief §5.6).
            $table->char('order_number_prefix', 1)->unique();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /**
         * Runtime settings, read on every checkout (brief §5.2).
         *
         * Service charge, delivery fee and cutoff defaults live here rather than in config
         * so she can change them without a deploy. Never hardcode any of them.
         *
         * Typed by `cast` so a value round-trips as the shape it was written as — a bare
         * string store turns `false` into `"false"`, which is truthy.
         */
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('cast')->default('string'); // string|int|bool|json
            $table->string('group')->default('general');
            $table->text('description')->nullable();

            // Whether the public `checkout-config` endpoint may expose it. Defaults to
            // false: a setting is private until someone decides otherwise, so adding one
            // can never accidentally leak.
            $table->boolean('is_public')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('branches');
    }
};
