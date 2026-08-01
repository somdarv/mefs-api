<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\Auth\OtpService;
use App\Support\GhanaPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer login. Phone plus a one-time code — no password is ever set.
 *
 * ⚠️⚠️ THE TOKEN MINTED HERE MUST NEVER CARRY THE `staff` ABILITY. ⚠️⚠️
 *
 * That is brief Law 3, and it has a specific trap attached: `createToken($name)` with no
 * second argument defaults to `['*']`, and Sanctum's `can()` honours the wildcard. A
 * customer token minted without thinking about abilities would therefore satisfy any
 * `can:staff` check in the framework's own terms.
 *
 * Two things stop that. This controller passes an explicit ability list, and
 * `EnsureStaffAbility` does a membership check rather than calling `can()` — see that
 * middleware for the full argument. Both are needed: one is the intent, the other is the
 * thing that holds when somebody forgets.
 */
final class CustomerAuthController extends Controller
{
    /**
     * The only ability a customer token ever holds. Named here, next to the code that mints
     * it, so a grep for the constant finds every place a customer credential is created.
     */
    public const CUSTOMER_ABILITY = 'customer';

    public function __construct(private readonly OtpService $otp) {}

    /**
     * "Text me a code."
     *
     * ⚠️ ALWAYS 200, WHATEVER THE NUMBER. There is nothing to enumerate — any Ghanaian
     * mobile may request a code, and the customer record is created on first successful
     * verification — so signup and login are the same act and the response cannot leak
     * whether the number is known.
     *
     * Throttled on the route by IP; the cooldown below is per NUMBER, which is the one an
     * attacker cannot rotate around and the one that stops a double-tap costing two texts.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
        ]);

        $phone = GhanaPhone::normalise($data['phone']);

        // The rule already passed, so this is belt and braces — but `normalise` returning
        // null and being used as a database key is the kind of thing that fails silently.
        abort_if($phone === null, 422, 'Enter a Ghanaian mobile number, like 024 123 4567.');

        $wait = $this->otp->secondsUntilNextRequest($phone);

        if ($wait > 0) {
            /*
             * 429 with the wait, not a silent success. A customer who did not get the text
             * needs to know when to press the button again; pretending to resend and doing
             * nothing leaves them tapping a button that appears broken.
             */
            return ApiResponse::error("Wait {$wait} seconds before asking for another code.", 429, [
                'retry_after' => [$wait],
            ]);
        }

        $this->otp->request($phone, $request->ip());

        return ApiResponse::success(
            ['expires_in' => \App\Models\Otp::LIFETIME_MINUTES * 60],
            'Code sent',
        );
    }

    /**
     * The code, and a token if it is right.
     *
     * ⚠️ ONE MESSAGE FOR EVERY KIND OF FAILURE. "No code was requested for this number",
     * "that code expired" and "that code is wrong" are three facts, and together they let
     * somebody probe which numbers have logged in recently. The service returns null for all
     * of them for exactly this reason.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new PhoneNumber],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $phone = GhanaPhone::normalise($data['phone']);
        abort_if($phone === null, 422, 'Enter a Ghanaian mobile number, like 024 123 4567.');

        $customer = $this->otp->verify($phone, $data['code']);

        if ($customer === null) {
            return ApiResponse::error('That code is not right, or it has expired.', 422, [
                'code' => ['That code is not right, or it has expired.'],
            ]);
        }

        $user = $this->userFor($customer);

        return ApiResponse::success([
            // ⚠️ EXPLICIT ABILITIES. See the class comment for what the default would do.
            'token' => $user->createToken('customer', [self::CUSTOMER_ABILITY])->plainTextToken,
            'customer' => new CustomerResource($customer),
        ], 'Signed in');
    }

    public function me(Request $request): JsonResponse
    {
        $customer = $this->customerOrFail($request);

        return ApiResponse::success(new CustomerResource($customer), $customer->name);
    }

    /**
     * Their saved details.
     *
     * ⚠️ DEFAULTS, NOT AUTHORITY. Changing an address here does not touch a single existing
     * order: those snapshot what was actually used, so a customer who moves does not rewrite
     * where last month's food went. `phone` is absent from the rules because it is the
     * identity this account is keyed on — changing it is a different account.
     */
    public function update(Request $request): JsonResponse
    {
        $customer = $this->customerOrFail($request);

        $customer->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'default_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'default_area' => ['sometimes', 'nullable', 'string', 'max:120'],
            'default_delivery_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]));

        return ApiResponse::success(new CustomerResource($customer->refresh()), 'Saved');
    }

    /** This device only. A customer signing out of a phone keeps their other sessions. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Signed out');
    }

    /**
     * The `users` row behind a customer, created on first login.
     *
     * ⚠️ NO PASSWORD IS SET, AND THAT IS THE POINT. A customer account has no password to
     * leak, reset or reuse from another site. The column is nullable for exactly this — see
     * the users migration.
     *
     * ⚠️ AND NO ROLE IS ASSIGNED. `Permission::byRole()` lists `customer` with an empty
     * permission set, so the absence is asserted in code rather than being an omission
     * somebody has to notice. A customer holds nothing.
     */
    private function userFor(Customer $customer): User
    {
        if ($customer->user !== null) {
            return $customer->user;
        }

        return DB::transaction(function () use ($customer): User {
            $user = User::query()->create([
                'name' => $customer->name,
                /*
                 * `users.email` is unique and NOT NULL. A customer signing up by phone has
                 * no email, so a placeholder keyed on the phone keeps the index honest
                 * without inventing a deliverable address — and `@customer.mefs.local` is
                 * not a domain anything will try to send to.
                 */
                'email' => $customer->phone.'@customer.mefs.local',
                'phone' => $customer->phone,

                /*
                 * ⚠️ A LONG RANDOM STRING, NOT AN EMPTY ONE OR A NULL.
                 *
                 * There is no password login for customers, so this is never checked — but a
                 * null or empty hash is a value somebody's future `Hash::check($input, ...)`
                 * could match, and "" is a very guessable password. Random and discarded
                 * means there is no password rather than a weak one.
                 */
                'password' => Str::random(64),
            ]);

            /*
             * Set after the create, not in it. `phone_verified_at` is deliberately absent
             * from `User::$fillable` — that list is narrow because mass assignment is how
             * the original's role takeover worked (§4.3) — and "this number has been
             * verified" is a claim only this code path is entitled to make, having just
             * watched them prove it.
             */
            $user->forceFill(['phone_verified_at' => now()])->save();

            $customer->user_id = $user->id;
            $customer->save();

            return $user;
        });
    }

    /**
     * ⚠️ 403, NOT A NEW CUSTOMER ROW.
     *
     * A staff token satisfies `auth:sanctum` on these routes just as a customer one does,
     * and a staff user has no customer profile. Creating one on the fly would silently give
     * every staff member a shadow customer account keyed to their work number.
     */
    private function customerOrFail(Request $request): Customer
    {
        $customer = $request->user()?->customer;

        abort_if($customer === null, 403, 'This is a customer account route.');

        return $customer;
    }
}
