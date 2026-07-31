<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Staff sign-in. One endpoint for every staff role; the role decides where the client
 * lands, not which endpoint it calls.
 */
final class StaffAuthController extends Controller
{
    /**
     * The ability that separates a staff token from a customer one (brief Law 3).
     *
     * A single `users` table means an employee who also orders lunch is one row. Without
     * this, the softer of the two login paths — customer OTP, no password — would mint a
     * token that reaches every staff route. The ability is what makes one table safe.
     */
    public const STAFF_ABILITY = 'staff';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],      // email or phone
            'password' => ['required', 'string'],
        ]);

        $this->assertNotThrottled($request);

        $user = $this->findByEmailOrPhone($credentials['login']);

        // One check covering "no such user" and "wrong password", so the response cannot
        // be used to enumerate which staff accounts exist. Hash::check is still called on a
        // dummy when the user is missing, to keep the timing indistinguishable.
        $passwordOk = $user?->password !== null
            && Hash::check($credentials['password'], $user->password);

        if (! $passwordOk) {
            Hash::check($credentials['password'], '$2y$12$'.str_repeat('0', 53));
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'login' => ['Those details do not match our records.'],
            ]);
        }

        // Holding a password is not the same as being staff. A customer who somehow has one
        // must not receive a staff token.
        if (! $user->isStaff()) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'login' => ['Those details do not match our records.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $token = $user->createToken(
            name: 'staff:'.$request->userAgent(),
            abilities: [self::STAFF_ABILITY],
        );

        return ApiResponse::success([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->roleEnum()?->value,
                'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            ],
        ], 'Signed in');
    }

    public function logout(Request $request): JsonResponse
    {
        // This token only. Signing out on a laptop must not sign her out on the phone she
        // is holding in the kitchen.
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(null, 'Signed out');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->roleEnum()?->value,
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
        ], 'Current user');
    }

    private function findByEmailOrPhone(string $login): ?User
    {
        return User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();
    }

    private function assertNotThrottled(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request), maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'login' => [
                    'Too many attempts. Try again in '
                    .RateLimiter::availableIn($this->throttleKey($request)).' seconds.',
                ],
            ]);
        }
    }

    /** Keyed on login + IP so one attacker cannot lock a real user out by guessing at them. */
    private function throttleKey(Request $request): string
    {
        return 'staff-login:'.mb_strtolower((string) $request->input('login')).'|'.$request->ip();
    }
}
