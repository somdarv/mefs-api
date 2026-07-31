<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CycleController as AdminCycleController;
use App\Http\Controllers\Admin\MenuItemController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\CheckoutConfigController;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Controllers\CycleController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderTrackingController;
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

    // Login is unauthenticated by definition, and is the route most worth guessing at.
    // The controller additionally throttles per login+IP so one attacker cannot lock a
    // real user out by guessing at them.
    Route::post('staff/login', [StaffAuthController::class, 'login'])->name('staff.login');

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
    });
});
