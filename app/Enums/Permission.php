<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Permissions. The brief's 75 collapse to these for a two-surface v1.
 *
 * **Narrow, verb-per-document — never coarse.** The single most exploitable mistake in the
 * original was a coarse `manage_employees` held by a role that only needed to edit notes
 * (brief §4.3). A coarse grant is a grant of everything the endpoint happens to accept.
 *
 * A permission that exists but is referenced by no route is also a trap: the original had
 * `view_analytics` granted to exactly the right roles and used by **zero** routes, while
 * analytics actually sat behind `view_orders`. It looked enforced and was not.
 * `PermissionCoverageTest` fails if any case here goes unreferenced.
 */
enum Permission: string
{
    // ── Orders ────────────────────────────────────────────────────────────────
    case OrdersView = 'orders.view';
    case OrdersCreate = 'orders.create';           // includes admin manual entry
    case OrdersAdvance = 'orders.advance';         // move along the status machine
    case OrdersCancel = 'orders.cancel';
    case OrdersRefund = 'orders.refund';

    // ── Cycles ────────────────────────────────────────────────────────────────
    case CyclesView = 'cycles.view';
    case CyclesManage = 'cycles.manage';           // create, edit windows, the dish matrix

    /**
     * Deliberately separate from `cycles.manage`. Forcing orders open past the cutoff, or
     * slamming them shut mid-window, is a different act from editing next week's plan — it
     * changes what the public can do *right now*, and it is always logged with a reason.
     */
    case CyclesOverride = 'cycles.override';

    // ── Menu ──────────────────────────────────────────────────────────────────
    case MenuView = 'menu.view';
    case MenuManage = 'menu.manage';               // dishes, options, photos

    /**
     * Split from `menu.manage` because the brief splits it (§3.3): availability is an
     * operational call, price is an ownership call. Collapsing them means anyone who can
     * mark a dish sold out can also reprice it.
     */
    case MenuPrice = 'menu.price';

    // ── Money ─────────────────────────────────────────────────────────────────
    case PaymentsView = 'payments.view';

    /**
     * Writing what actually landed in the bank, from a settlement file.
     *
     * Split from `payments.view` for the same reason `menu.price` is split from
     * `menu.manage`: reading what was charged is an operational act, and asserting what was
     * received is an ownership one. Collapsing them means anyone who can look at the
     * payments list can also rewrite the settled column, and a settled column that anyone
     * can rewrite is not evidence of anything.
     */
    case PaymentsReconcile = 'payments.reconcile';

    case AnalyticsView = 'analytics.view';         // NOT orders.view — brief Phase 5 gate

    // ── Marketing ─────────────────────────────────────────────────────────────
    case PromosView = 'promos.view';

    /**
     * Creating and editing discount codes.
     *
     * A promo is a money instrument, not a content edit. "20% off, no cap, unlimited uses"
     * is a sentence anyone holding this can write, and it costs whatever the week's orders
     * come to. Split from `promos.view` so a future role can read which codes are running
     * without being able to mint one.
     */
    case PromosManage = 'promos.manage';

    /**
     * The storefront's promotional strip.
     *
     * ⚠️ ONE PERMISSION, NOT TWO, AND NO `banners.view` — because reading banners is not a
     * staff act at all. They are published to the storefront, so every customer already sees
     * every live one; a permission to view them would guard nothing.
     *
     * Separate from `promos.manage` because a banner is content and a promo is money. A
     * banner says "jollof base is here"; it does not take anything off a bill.
     */
    case BannersManage = 'banners.manage';

    // ── People ────────────────────────────────────────────────────────────────
    case CustomersView = 'customers.view';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';             // gated further by the role ceiling

    // ── Platform ──────────────────────────────────────────────────────────────
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';

    /**
     * Which permissions each role holds.
     *
     * `customer` appears with an empty list on purpose: it makes "a customer holds nothing"
     * an assertion in code rather than an omission someone has to notice.
     *
     * @return array<string, list<self>>
     */
    public static function byRole(): array
    {
        $admin = [
            self::OrdersView, self::OrdersCreate, self::OrdersAdvance,
            self::OrdersCancel, self::OrdersRefund,
            self::CyclesView, self::CyclesManage, self::CyclesOverride,
            self::MenuView, self::MenuManage, self::MenuPrice,
            self::PaymentsView, self::PaymentsReconcile, self::AnalyticsView,
            self::PromosView, self::PromosManage, self::BannersManage,
            self::CustomersView,
        ];

        return [
            // The owner holds everything, including staff management and settings.
            Role::TechAdmin->value => self::cases(),

            // She runs the kitchen. Note what is absent: staff.manage and settings.manage.
            // She is the only staff member today, so neither is needed, and a permission
            // granted "just in case" is how the original's takeover worked.
            Role::Admin->value => $admin,

            Role::Customer->value => [],
        ];
    }
}
