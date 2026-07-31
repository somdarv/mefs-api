<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
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
| 2. Every unauthenticated route gets a rate limit (brief Phase 10). Public routes are
|    grouped below so it stays obvious which ones those are.
|
*/

// ── Public ────────────────────────────────────────────────────────────────────
Route::get('health', HealthController::class)->name('health');

// ── Authenticated ─────────────────────────────────────────────────────────────
// `auth:sanctum` alone is not enough for staff routes — a customer OTP token satisfies it.
// Staff groups additionally require the `staff` token ability (brief Law 3), which arrives
// in M1 along with the login endpoints.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', fn (Request $request) => ApiResponse::success(
        $request->user(),
        'Current user',
    ))->name('me');
});
