<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createAuditLog();
        $this->createBanners();
    }

    /**
     * Who changed what, and what it was before.
     *
     * ⚠️ NOT AN EVENT LOG. This records **acts of authority** — the handful of things where
     * "who decided this, and when" is a question somebody will actually ask: forcing a
     * closed week back open, repricing a dish, minting a discount code, asserting what
     * landed in the bank, changing what a member of staff may do.
     *
     * Logging every write would bury those under thousands of rows of routine, and a log
     * nobody reads is not an audit trail — it is disk usage that looks like diligence. Order
     * status changes already have `order_status_history`, which is richer and per-order;
     * duplicating them here would give two accounts of the same fact that can disagree.
     */
    private function createAuditLog(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();

            /**
             * ⚠️ NULLABLE, AND THE NULL MEANS THE SYSTEM DID IT.
             *
             * A scheduled job releasing an expired hold has no actor, and recording one
             * would be a lie about who decided. `nullOnDelete` rather than cascade for the
             * same reason a receipt outlives a menu edit: deleting a staff account must not
             * quietly erase what they did.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /** Snapshot. The name at the time, so a rename does not rewrite the history. */
            $table->string('actor_name')->nullable();

            /** Verb-and-object, e.g. `cycle.force_open`, `promo.created`, `menu.repriced`. */
            $table->string('action')->index();

            // Which row. Nullable together — a settlement import is an act with no single
            // subject, and inventing one would be worse than saying so.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            /** Human-readable, written at the time. What the list actually renders. */
            $table->string('summary');

            /**
             * ⚠️ BEFORE AND AFTER, NOT A DIFF.
             *
             * "price: 4000 → 4500" is reconstructable from these two; the reverse is not.
             * A stored diff cannot answer "what was it actually set to", which is the
             * question asked when two people remember a number differently.
             */
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            /** Why, when the actor was asked for a reason. Overrides always are. */
            $table->text('reason')->nullable();

            $table->string('ip', 45)->nullable();

            $table->timestampTz('created_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    /**
     * The storefront's promotional strip.
     *
     * ⚠️ CONTENT, NOT A DISCOUNT. A banner says "jollof base is here"; it does not take
     * money off anything. The two live in different tables because they are different acts
     * with different consequences — and `promos.manage` is a money grant while this is a
     * content one.
     */
    private function createBanners(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('body')->nullable();

            /** Where it goes when tapped. Relative, so it survives a domain change. */
            $table->string('link_url')->nullable();
            $table->string('link_label')->nullable();

            $table->string('image_path')->nullable();

            /**
             * Which visual treatment. A short list rather than free colour input: a banner
             * with a hand-picked hex is a banner that stops matching the brand the first
             * time the palette moves.
             */
            $table->string('tone')->default('brand');

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            /** Lower sorts first. Ties break on id, so the order is always total. */
            $table->integer('position')->default(0);

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('audit_log');
    }
};
