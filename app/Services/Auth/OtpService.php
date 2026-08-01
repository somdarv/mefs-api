<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\SendSms;
use App\Models\Customer;
use App\Models\Otp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Customer login: a phone number and a six-digit code. No password is ever set.
 *
 * ⚠️⚠️ FOUR RULES, AND EACH ONE IS A THING THAT GOES WRONG WITHOUT IT. ⚠️⚠️
 *
 * 1. **The code is hashed at rest.** This table is a list of live credentials; a read of it
 *    must not be a way in. The same reasoning as §10.14's `recoverable_password`.
 *
 * 2. **Attempts are counted on the OTP row, not only per IP.** Rate limiting by address
 *    alone gives an attacker with a handful of addresses a handful of budgets against the
 *    same code. The counter travels with the credential.
 *
 * 3. **Requesting a code invalidates the previous one.** Two live codes for one number
 *    doubles the guessing surface, and the customer only ever has the newest text in front
 *    of them anyway.
 *
 * 4. ⚠️ **THE CODE IS NEVER RETURNED, LOGGED OR PUT IN A RESPONSE.** Not in `local`, not
 *    behind a debug flag. A convenience that hands out live credentials in one environment
 *    is a convenience that ships. Local development reads it from the `log` SMS driver's
 *    output, which is where the message actually went.
 */
final class OtpService
{
    /**
     * Send a code to a number.
     *
     * ⚠️ THIS DOES NOT REVEAL WHETHER THE NUMBER IS KNOWN, and cannot, because there is
     * nothing to reveal: any Ghanaian mobile may request a code, and the customer record is
     * created on first successful verification. Signup and login are the same act, which is
     * both simpler and closes the enumeration question by construction.
     */
    public function request(string $phone, ?string $ip = null): void
    {
        // Rule 3. Consumed rather than deleted, so the history of "a code was issued at
        // 14:02" survives — which is what makes a support conversation possible.
        Otp::query()
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        Otp::query()->create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(Otp::LIFETIME_MINUTES),
            'request_ip' => $ip,
        ]);

        /*
         * Queued like every other message, so a slow gateway cannot slow a login screen —
         * and so a gateway outage delays a code rather than failing the request with an
         * error the customer cannot act on.
         *
         * ⚠️ THE CONTEXT STRING CARRIES NO CODE. It goes into log lines.
         */
        if (config('sms.enabled', true)) {
            SendSms::dispatch(
                $phone,
                "{$code} is your Mef's Kitchen code. It expires in ".Otp::LIFETIME_MINUTES.' minutes.',
                'otp',
            );
        }
    }

    /**
     * Check a code, and return the customer it belongs to.
     *
     * Returns null on every kind of failure — no live code, wrong code, expired, burned
     * through its attempts. ⚠️ THE CALLER MUST NOT DISTINGUISH THESE TO THE USER: "no code
     * was requested for this number" and "that code is wrong" together let somebody probe
     * which numbers have logged in recently.
     */
    public function verify(string $phone, string $code): ?Customer
    {
        $otp = Otp::query()
            ->where('phone', $phone)
            ->live()
            ->latest('id')
            ->first();

        if ($otp === null) {
            return null;
        }

        /*
         * ⚠️ INCREMENTED BEFORE THE COMPARISON, NOT AFTER.
         *
         * Counting only failures looks equivalent and is not: an exception, a timeout or a
         * connection dropped mid-check leaves the attempt uncounted, and an attacker who can
         * cause one gets unlimited guesses. Charging for the attempt up front means the
         * budget is spent whatever happens next.
         */
        $otp->increment('attempts');

        if (! Hash::check($code, $otp->code_hash)) {
            return null;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return $this->customerFor($phone);
    }

    /**
     * The customer this number belongs to, created if this is their first login.
     *
     * ⚠️ `firstOrCreate` ON THE PHONE, WHICH IS UNIQUE — so a guest who has been ordering
     * for months adopts their existing record rather than getting a second one. Their order
     * history is keyed on the same number and appears the moment they log in, which is the
     * whole point of letting guests order without an account.
     */
    private function customerFor(string $phone): Customer
    {
        $customer = Customer::query()->firstOrNew(['phone' => $phone]);

        if (! $customer->exists) {
            // Their name comes from their most recent order if they have one — a customer
            // who has ordered four times should not be asked who they are.
            $customer->name = \App\Models\Order::query()
                ->where('contact_phone', $phone)
                ->latest('id')
                ->value('contact_name') ?? 'Customer';

            $customer->save();
        }

        return $customer;
    }

    /**
     * Six digits, from a cryptographically secure source.
     *
     * ⚠️ `random_int`, NEVER `rand()` OR `mt_rand()`. Both are seeded pseudo-random and
     * predictable from a handful of outputs — for a credential that is not a style
     * preference. `str_pad` because 000123 is a valid code and `random_int` returns 123.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * How long a caller should wait before asking for another code.
     *
     * Returns 0 when they may ask now. Used to give an honest "try again in 40 seconds"
     * rather than a bare refusal — a customer who did not receive a text needs to know when
     * to press the button, not that they pressed it too soon.
     */
    public function secondsUntilNextRequest(string $phone): int
    {
        $last = Otp::query()->where('phone', $phone)->latest('id')->first();

        if ($last === null) {
            return 0;
        }

        $earliest = $last->created_at->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return max(0, (int) ceil(now()->diffInSeconds($earliest, false)));
    }

    /**
     * Long enough that a fat-fingered double-tap does not burn two texts, short enough that
     * a customer whose message genuinely went missing is not stuck staring at a screen.
     * Each SMS costs money, and this is the cheapest place to stop wasting them.
     */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Housekeeping. Consumed and expired codes are dead weight, and they are credentials. */
    public function pruneExpired(): int
    {
        $cutoff = now()->subDay();

        $count = Otp::query()->where('created_at', '<', $cutoff)->delete();

        if ($count > 0) {
            Log::info('Pruned expired OTPs', ['count' => $count]);
        }

        return $count;
    }
}
