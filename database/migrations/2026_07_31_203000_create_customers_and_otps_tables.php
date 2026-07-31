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
         * The customer profile. Separate from `users` because it holds ordering facts —
         * default address, order counts — that mean nothing for a staff-only account.
         *
         * `user_id` is nullable because a **guest** may order with no account at all, and a
         * guest still needs somewhere to hang an order history keyed by phone. Every query
         * that joins customer must tolerate the null (brief trap §10.6).
         */
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone', 20)->unique();
            $table->string('email')->nullable();

            // Defaults offered at checkout, not authority. The order snapshots what was
            // actually used, because a customer who moves must not rewrite old receipts.
            $table->string('default_address')->nullable();
            $table->string('default_area')->nullable();
            $table->text('default_delivery_note')->nullable();

            // Staff-only. Never rendered on a customer surface.
            $table->text('internal_notes')->nullable();

            $table->timestamps();
        });

        /**
         * Customer login is phone + one-time code. No password is ever set.
         *
         * The code is stored **hashed**, not in clear: this table is a list of live
         * credentials, and a read of it must not be a way in. Verification hashes the
         * submitted code and compares.
         */
        Schema::create('otps', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Brute force is the obvious attack on a 6-digit code, so attempts are counted
            // against the OTP row itself rather than only rate-limited by IP.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->string('request_ip', 45)->nullable();
            $table->timestamps();
        });

        // The lookup on every verify: newest live code for a phone.
        Schema::table('otps', function (Blueprint $table): void {
            $table->index(['phone', 'consumed_at', 'expires_at'], 'otps_live_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otps');
        Schema::dropIfExists('customers');
    }
};
