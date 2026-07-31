<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\CheckoutConfigController;
use App\Http\Controllers\HealthController;
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

    // Login is unauthenticated by definition, and is the route most worth guessing at.
    // The controller additionally throttles per login+IP so one attacker cannot lock a
    // real user out by guessing at them.
    Route::post('staff/login', [StaffAuthController::class, 'login'])->name('staff.login');
});

// ── Staff ─────────────────────────────────────────────────────────────────────
// `auth:sanctum` alone is NOT enough — a customer OTP token satisfies it. The `staff`
// middleware requires the ability minted only by the staff login path, and fails closed.
Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::post('staff/logout', [StaffAuthController::class, 'logout'])->name('staff.logout');
    Route::get('staff/me', [StaffAuthController::class, 'me'])->name('staff.me');
});
