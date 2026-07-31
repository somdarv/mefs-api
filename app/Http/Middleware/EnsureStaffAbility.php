<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every staff route sits behind this, in addition to `auth:sanctum`.
 *
 * ⚠️ FAILS CLOSED, AND SAYS SO (brief trap §10.1).
 *
 * The original's equivalent called `$next()` whenever it could not find something to check
 * against — so mounting it on a route with nothing to compare guarded exactly nothing while
 * looking, in the route file, like protection. Here every path that is not an explicit pass
 * is a refusal, and each refusal is logged: a middleware that silently refuses is its own
 * kind of outage, because nobody can tell a bug from a policy.
 */
final class EnsureStaffAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->refuse($request, 'no authenticated principal', 401);
        }

        $token = $user->currentAccessToken();

        if ($token === null) {
            // Session auth rather than a token — no abilities to inspect, so no verdict can
            // be reached. No verdict is a refusal here.
            return $this->refuse($request, 'no access token to inspect', 401);
        }

        /*
         * The wall between the two login paths. A customer OTP token satisfies
         * `auth:sanctum` perfectly well; it must not satisfy this.
         *
         * ⚠️ DELIBERATELY NOT `$token->can('staff')`.
         *
         * Sanctum's `can()` honours the `*` wildcard — and `createToken($name)` with no
         * abilities argument defaults to exactly `['*']`. So the obvious implementation
         * hands staff access to every casually-minted token, including any customer token
         * written without thinking about abilities. That is brief Law 3 defeated by a
         * default parameter.
         *
         * An explicit membership check means a token is staff only if someone deliberately
         * said so.
         */
        $abilities = (array) $token->abilities;

        if (! in_array(StaffAuthController::STAFF_ABILITY, $abilities, true)) {
            return $this->refuse($request, 'token lacks the staff ability', 403);
        }

        // Belt and braces: the token says staff, but the role is the durable fact. A role
        // revoked after a token was minted must take effect immediately, not at expiry.
        if (! $user->isStaff()) {
            return $this->refuse($request, 'user holds no staff role', 403);
        }

        return $next($request);
    }

    private function refuse(Request $request, string $reason, int $status): Response
    {
        Log::warning('Staff route refused', [
            'reason' => $reason,
            'route' => $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        return $status === 401
            ? ApiResponse::error('Unauthenticated.', 401)
            : ApiResponse::error('This action requires a staff account.', 403);
    }
}
