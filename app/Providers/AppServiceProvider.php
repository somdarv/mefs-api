<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Payments\PaystackClient;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\OrderMessages;
use App\Services\Sms\SmsOnlineGhSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindSmsSender();

        $this->app->singleton(
            OrderMessages::class,
            fn () => new OrderMessages(config('app.kitchen_phone', '+233241915464')),
        );

        // Constructed from config rather than reading it inside the client, so a test can
        // hand it a key without touching the environment — and so `isConfigured()` is a
        // property of the object rather than a global lookup that could differ per call.
        $this->app->singleton(PaystackClient::class, fn () => new PaystackClient(
            secretKey: (string) config('paystack.secret_key'),
            baseUrl: (string) config('paystack.base_url'),
            timeout: (int) config('paystack.timeout'),
        ));
    }

    /**
     * Which SMS driver is live.
     *
     * ⚠️ IT FALLS BACK TO `log` WHEN CREDENTIALS ARE MISSING, and that direction is the
     * whole point: a half-configured environment must text NOBODY rather than everybody. The
     * failure mode of the opposite default — pick the real gateway, discover at runtime that
     * the key is blank — is a queue full of rejected messages and, on a bad day, a live
     * gateway reached from a test run.
     *
     * A singleton so `LogSmsSender` keeps what it sent within a process, which is what tests
     * assert against and what makes "what did we just send her?" answerable locally.
     */
    private function bindSmsSender(): void
    {
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            $config = config('sms.smsonlinegh');

            if (config('sms.driver') !== 'smsonlinegh' || ($config['key'] ?? '') === '') {
                return new LogSmsSender;
            }

            return new SmsOnlineGhSender(
                apiKey: $config['key'],
                senderId: $config['sender_id'],
                baseUrl: $config['base_url'],
                timeout: $config['timeout'],
            );
        });
    }

    public function boot(): void
    {
        // Laravel auto-discovers policies by naming convention, but UserPolicy carries the
        // role ceiling and the self-edit guard — a one-request platform takeover in the
        // original (brief trap §10.2). Registering it explicitly means a rename or a moved
        // file cannot silently unhook the most security-sensitive policy in the system.
        Gate::policy(User::class, UserPolicy::class);

        // Both of these are silent in production and both are how a `role` field ends up
        // writable: a discarded attribute looks like the write succeeded.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerRateLimiters();
    }

    /**
     * ⚠️⚠️ NAMED LIMITERS, BECAUSE TWO ANONYMOUS `throttle:` MIDDLEWARE ON ONE ROUTE SHARE
     * A COUNTER. ⚠️⚠️
     *
     * `ThrottleRequests` builds its cache key from the request signature — domain and IP for
     * an unauthenticated caller — and nothing about which middleware instance is asking. So
     * a route inside a `throttle:60,1` group that adds its own `throttle:12,1` increments
     * **one** bucket twice per request, and the tighter limit trips at six requests rather
     * than twelve. The limit that appears in the route file is not the limit that applies.
     *
     * That is exactly the kind of guard that looks enforced and is not, and it cost a
     * confusing test failure to find. A named limiter prefixes its own name into the key, so
     * each has its own budget and the number in the route file is the number that applies.
     *
     * It also buys the thing an IP budget cannot do on its own: keying on the **phone
     * number**, which an attacker cannot rotate around by changing address.
     */
    private function registerRateLimiters(): void
    {
        /*
         * Asking for a code. Six a minute per address is generous for a human and useless
         * for a script — and the per-number cooldown in `OtpService` is the real limit, since
         * each of these costs money to send.
         */
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinute(6)->by('ip:'.$request->ip()),
            Limit::perMinute(3)->by('phone:'.$request->input('phone')),
        ]);

        /*
         * Submitting one. ⚠️ THIS IS NOT THE REAL DEFENCE — five attempts counted on the OTP
         * row is (`Otp::MAX_ATTEMPTS`), because an attacker with a handful of addresses gets
         * a handful of IP budgets against the same code. This is the outer wall; the counter
         * on the credential is the inner one.
         */
        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('phone:'.$request->input('phone')),
        ]);
    }
}
