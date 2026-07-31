<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE users table for everyone — staff and customers (brief §3.4).
 *
 * Splitting them is tempting and wrong. A cook ordering lunch on her day off is one human
 * with one phone number; two tables means two identities, duplicate accounts, and a
 * reconciliation problem forever. Safety comes from token abilities (brief Law 3), not from
 * separate tables: a token minted by customer OTP login never carries the `staff` ability,
 * so it cannot reach a staff route even if that user genuinely holds staff permissions.
 *
 * Edited in place rather than altered by a later migration — the project is greenfield and
 * there is no data to preserve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // Both nullable: a customer may exist with a phone and no email, and staff may
            // sign in with either. Unique where present.
            $table->string('email')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();

            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();

            // Nullable: an OTP-only customer never sets one. Staff always have one.
            //
            // There is deliberately NO `recoverable_password` column. The original carries a
            // decryptable copy of every password; it is an anti-pattern, not a feature
            // (brief trap §10.14). Staff recovery goes through a reset token.
            $table->string('password')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        // A user with neither an email nor a phone can never sign in and can never be
        // contacted. Enforced in the database because it is an invariant of the row, not a
        // rule of one form.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_identifier_present CHECK (email IS NOT NULL OR phone IS NOT NULL)');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
