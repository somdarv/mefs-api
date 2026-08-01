<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by `php artisan schedule:work` in development, and in production by a single
| cron entry calling `schedule:run` every minute.
|
| ⚠️ A JOB THAT IS NOT SCHEDULED IS A FEATURE THAT DOES NOT EXIST. The hold-expiry
| mechanism sat fully written and completely inert for exactly as long as this file
| went unedited — every order carrying an expiry that nothing read.
|
*/

/**
 * Give back the slots nobody paid for (departure #6).
 *
 * Every five minutes: the shortest hold is a 30-minute payment window, so five minutes is
 * fine-grained enough that a released slot returns while the customer who wants it is still
 * looking, and coarse enough not to be a query every minute forever.
 *
 * `withoutOverlapping` so a slow run cannot have a second copy racing it over the same rows.
 * Both would try to cancel the same order; one would lose on the status machine's own guard,
 * which is correct but is noise in the log that means nothing.
 */
Schedule::command('orders:release-expired-holds')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
