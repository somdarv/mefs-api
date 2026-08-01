<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\OrderMessages;
use App\Services\Sms\SmsOnlineGhSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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
    }
}
