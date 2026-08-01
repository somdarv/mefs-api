<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\CycleController as AdminCycleController;
use App\Http\Controllers\Admin\InsightsController;
use App\Http\Controllers\Admin\MenuItemController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PrepSheetController;
use App\Http\Controllers\Admin\PromoController;
use App\Http\Controllers\Auth\CustomerAuthController;
use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\CheckoutConfigController;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CycleController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderPaymentController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — prefixed `api/v1` in bootstrap/app.php
|--------------------------------------------------------------------------
|
| Two ordering rules apply as this file grows:
|
| 1. Declare LITERAL routes before WILDCARD ones. `orders/export` must come before
|    `orders/{order}` or the wildcard eats it (brief trap §10.7).
| 2. Every unauthenticated route gets a rate limit (brief Phase 10). The public group
|    below carries one, so a route added there inherits it rather than being forgotten.
|
*/

// ── Public ────────────────────────────────────────────────────────────────────
Route::get('health', HealthController::class)->name('health');

Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('checkout-config', CheckoutConfigController::class)->name('checkout-config');

    // `menu/pantry` is LITERAL and `menu/day/{date}` is parameterised on a different
    // segment, so they cannot collide — but keep the literal first regardless, because the
    // day this file grows a `menu/{item}` the wildcard eats everything (brief trap §10.7).
    Route::get('menu/pantry', [MenuController::class, 'pantry'])->name('menu.pantry');
    Route::get('menu/day/{date}', [MenuController::class, 'day'])->name('menu.day');

    // The date picker's whole source of truth: which days exist and, per day, whether
    // ordering is open and why not.
    Route::get('cycles/current', [CycleController::class, 'current'])->name('cycles.current');

    // Live banners only — a banner scheduled for Friday must not be readable on Tuesday
    // by anyone who opens the network tab.
    Route::get('banners', BannerController::class)->name('banners');

    /*
     * "Text me if a portion comes back." The only place this system captures demand it
     * could not meet — every other table records what sold.
     *
     * Unauthenticated, because somebody who cannot buy the food is the last person to ask
     * for an account first.
     */
    Route::post('waitlist', [WaitlistController::class, 'store'])->name('waitlist.store');

    // Login is unauthenticated by definition, and is the route most worth guessing at.
    // The controller additionally throttles per login+IP so one attacker cannot lock a
    // real user out by guessing at them.
    Route::post('staff/login', [StaffAuthController::class, 'login'])->name('staff.login');

    /*
     * ── Customer login ────────────────────────────────────────────────────────
     *
     * Phone plus a one-time code.
     *
     * ⚠️ NAMED LIMITERS, NOT `throttle:6,1`. Two anonymous throttles on one route share a
     * cache key, so an inline limit inside this `throttle:60,1` group increments one bucket
     * twice per request and trips at half the number written here. See
     * `AppServiceProvider::registerRateLimiters()` — the limits are defined there, keyed on
     * the phone as well as the address.
     *
     * ⚠️ AND NEITHER IS THE REAL DEFENCE ON VERIFY — five attempts counted on the OTP row
     * itself is (`Otp::MAX_ATTEMPTS`). An IP budget alone gives somebody with a handful of
     * addresses a handful of budgets against the same code.
     */
    Route::post('customer/otp', [CustomerAuthController::class, 'request'])
        ->middleware('throttle:otp-request')
        ->name('customer.otp.request');

    Route::post('customer/otp/verify', [CustomerAuthController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('customer.otp.verify');

    /*
     * ── The basket, and the one customer-facing way it becomes an order ───────
     *
     * Unauthenticated because most customers are guests, and identified by the pair
     * (URL token, `X-Guest-Session` header). `confirm` calls `OrderCreator`, the same
     * service admin manual entry calls — see the controller docblock for why that is
     * the whole point of this file (brief §5.8).
     */
    Route::post('checkout-sessions', [CheckoutSessionController::class, 'store'])->name('checkout-sessions.store');
    Route::get('checkout-sessions/{token}', [CheckoutSessionController::class, 'show'])->name('checkout-sessions.show');
    Route::patch('checkout-sessions/{token}', [CheckoutSessionController::class, 'update'])->name('checkout-sessions.update');
    Route::post('checkout-sessions/{token}/confirm', [CheckoutSessionController::class, 'confirm'])->name('checkout-sessions.confirm');

    /*
     * "Where is my order?" — by RANDOM TOKEN, never by order number or id.
     *
     * The gateway redirects someone with no account here, so it cannot sit behind a login;
     * and an unauthenticated route keyed on a sequence is one a stranger can walk to read
     * off the day's volume and every customer's phone number (brief §5.6).
     */
    Route::get('orders/{token}', OrderTrackingController::class)->name('orders.track');

    /*
     * Paying for an order that already exists, and the return journey.
     *
     * ⚠️ `verify` exists because A BROWSER REDIRECT IS NOT PROOF OF PAYMENT. Paystack
     * sends the customer back to /orders/{token} and anyone can type that URL, so the
     * page asks the server, which asks Paystack with our secret key. It complements the
     * webhook rather than replacing it — both funnel into one PaymentRecorder, so a
     * race between them is a no-op instead of a double credit.
     *
     * Declared AFTER `orders/{token}` but on longer paths, so no wildcard conflict
     * arises; a future literal like `orders/export` would have to go above all three
     * (brief trap §10.7).
     */
    Route::post('orders/{token}/payment', [OrderPaymentController::class, 'start'])->name('orders.pay');
    Route::post('orders/{token}/payment/verify', [OrderPaymentController::class, 'verify'])->name('orders.pay.verify');
});

/*
 * ⚠️ THE WEBHOOK SITS OUTSIDE EVERY GROUP, AND THAT IS DELIBERATE.
 *
 * Not `auth:sanctum` — the gateway is not logged in and never will be. Not the public
 * throttle either: rate-limiting Paystack's retries would drop the one message that
 * proves a customer paid, and a dropped webhook is a paid order that looks unpaid.
 *
 * Its credential is the `x-paystack-signature` header, verified with HMAC-SHA512 over
 * the RAW body in the controller. That is a stronger proof than a session: it shows
 * possession of the secret key and that the body was not altered in transit.
 */
Route::post('webhooks/paystack', PaystackWebhookController::class)->name('webhooks.paystack');

/*
 * ── Signed-in customers ───────────────────────────────────────────────────────
 *
 * `auth:sanctum` and NOTHING ELSE — no `staff` middleware, which is the whole distinction.
 * A staff token satisfies these routes too, and that is fine: the controllers resolve the
 * caller's own customer profile and 403 when there isn't one, so a staff member reaching
 * here gets a refusal rather than a shadow customer account keyed to their work number.
 *
 * ⚠️ NOTHING HERE TAKES A `phone` OR `customer_id` PARAMETER. The identity comes from the
 * principal, always (brief Law 2) — a `phone` query parameter on the history route would be
 * an order-history endpoint for anybody else's number.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('customer/me', [CustomerAuthController::class, 'me'])->name('customer.me');
    Route::patch('customer/me', [CustomerAuthController::class, 'update'])->name('customer.update');
    Route::post('customer/logout', [CustomerAuthController::class, 'logout'])->name('customer.logout');

    Route::get('customer/orders', [CustomerOrderController::class, 'index'])->name('customer.orders');
});

// ── Staff ─────────────────────────────────────────────────────────────────────
// `auth:sanctum` alone is NOT enough — a customer OTP token satisfies it. The `staff`
// middleware requires the ability minted only by the staff login path, and fails closed.
Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::post('staff/logout', [StaffAuthController::class, 'logout'])->name('staff.logout');
    Route::get('staff/me', [StaffAuthController::class, 'me'])->name('staff.me');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        // Menu. Note `options` and `image` are declared as sub-resources of {item}, so no
        // literal-vs-wildcard hazard arises here.
        Route::get('menu/items', [MenuItemController::class, 'index'])->name('menu.index');
        Route::post('menu/items', [MenuItemController::class, 'store'])->name('menu.store');
        Route::get('menu/items/{item}', [MenuItemController::class, 'show'])->name('menu.show');
        Route::patch('menu/items/{item}', [MenuItemController::class, 'update'])->name('menu.update');
        Route::delete('menu/items/{item}', [MenuItemController::class, 'destroy'])->name('menu.destroy');
        Route::put('menu/items/{item}/options', [MenuItemController::class, 'syncOptions'])->name('menu.options');
        Route::post('menu/items/{item}/image', [MenuItemController::class, 'uploadImage'])->name('menu.image');

        // Cycles. The action routes are literal segments under {cycle}, so they cannot be
        // eaten by a wildcard — but note that `cycles/{cycle}` must stay declared after any
        // future literal like `cycles/upcoming` (brief trap §10.7).
        Route::get('cycles', [AdminCycleController::class, 'index'])->name('cycles.index');
        Route::post('cycles', [AdminCycleController::class, 'store'])->name('cycles.store');
        Route::get('cycles/{cycle}', [AdminCycleController::class, 'show'])->name('cycles.show');
        Route::patch('cycles/{cycle}', [AdminCycleController::class, 'update'])->name('cycles.update');
        Route::post('cycles/{cycle}/publish', [AdminCycleController::class, 'publish'])->name('cycles.publish');
        Route::post('cycles/{cycle}/clone', [AdminCycleController::class, 'clone'])->name('cycles.clone');

        // Separate permission from the rest — see the controller docblock.
        Route::post('cycles/{cycle}/override', [AdminCycleController::class, 'override'])->name('cycles.override');

        Route::patch('cycles/{cycle}/days/{day}', [AdminCycleController::class, 'updateDay'])->name('cycles.days.update');
        Route::put('cycles/{cycle}/days/{day}/items', [AdminCycleController::class, 'updateDayItems'])->name('cycles.days.items');

        /*
         * Orders. `store` is MANUAL ENTRY and it goes through `OrderCreator` like the
         * customer path does — there is deliberately no second creation route, which is
         * the trap the brief spends §5.8 and §10.9 on.
         *
         * `orders/{order}` is last: any literal like a future `orders/export` must be
         * declared above it or the wildcard eats it (brief trap §10.7).
         */
        Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::post('orders', [AdminOrderController::class, 'store'])->name('orders.store');
        Route::post('orders/{order}/status', [AdminOrderController::class, 'transition'])->name('orders.status');
        Route::post('orders/{order}/reject-cancellation', [AdminOrderController::class, 'rejectCancellation'])->name('orders.reject-cancellation');
        Route::get('orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');

        // Courier fields and the internal note. Deliberately narrow — see the controller.
        Route::patch('orders/{order}', [AdminOrderController::class, 'update'])->name('orders.update');

        /*
         * The screen she actually cooks from: one date, totalled per pot.
         *
         * A literal at the prefix root, so it collides with nothing — but note it sits
         * ABOVE nothing either, because `orders/{order}` is on a different first segment.
         */
        Route::get('prep-sheet', PrepSheetController::class)->name('prep-sheet');

        /*
         * ── Money ─────────────────────────────────────────────────────────
         *
         * `payments/settlements` is LITERAL and is declared first, ahead of any future
         * `payments/{payment}` — which is the rule this file opens with, and the one that
         * is only ever noticed after the wildcard has eaten something (trap §10.7).
         *
         * Three different permissions across three routes, and that spread is deliberate:
         * reading payments, asserting what was received, and seeing revenue are three
         * different grants. The original collapsed the third into `view_orders` and handed
         * the company's revenue to everyone who could look up an order (§4.3.4).
         */
        Route::post('payments/settlements', [PaymentController::class, 'settle'])->name('payments.settle');
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

        Route::get('insights', InsightsController::class)->name('insights');

        /*
         * ── Marketing ─────────────────────────────────────────────────────
         *
         * No `show`. The list returns everything a promo has — there are a handful of them,
         * not a catalogue — and a detail endpoint would be a second serialisation of the
         * same row to keep in step with the first.
         */
        Route::get('promos', [PromoController::class, 'index'])->name('promos.index');
        Route::post('promos', [PromoController::class, 'store'])->name('promos.store');
        Route::patch('promos/{promo}', [PromoController::class, 'update'])->name('promos.update');
        Route::delete('promos/{promo}', [PromoController::class, 'destroy'])->name('promos.destroy');

        Route::get('banners', [AdminBannerController::class, 'index'])->name('banners.index');
        Route::post('banners', [AdminBannerController::class, 'store'])->name('banners.store');
        Route::patch('banners/{banner}', [AdminBannerController::class, 'update'])->name('banners.update');
        Route::delete('banners/{banner}', [AdminBannerController::class, 'destroy'])->name('banners.destroy');

        /*
         * ── The audit log ─────────────────────────────────────────────────
         *
         * ⚠️ GET AND NOTHING ELSE. There is no PATCH and no DELETE here, for anyone,
         * including `tech_admin` — a row the most privileged account can edit is not
         * evidence. See `AuditController` for why `audit.view` is not on the admin role.
         */
        Route::get('audit', AuditController::class)->name('audit');
    });
});
