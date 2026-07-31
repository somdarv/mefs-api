<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
