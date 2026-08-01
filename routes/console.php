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

/**
 * "Your food is tomorrow."
 *
 * ⚠️ 5PM, NOT MIDNIGHT, AND THE TIME IS THE DESIGN.
 *
 * A reminder that arrives at 00:05 is read the next morning at the bottom of a night's
 * notifications, which is the same as not sending it. Late afternoon is when somebody can
 * still rearrange tomorrow — and it is late enough that an order placed today for tomorrow
 * is caught by the same run.
 *
 * ⚠️ `->timezone()` IS NOT OPTIONAL HERE. The scheduler runs on the server's clock, and a
 * server in UTC would fire this at 5pm UTC — 5pm in Accra by luck of GMT+0, and an hour out
 * the moment anything moves. Stating the timezone means the intent survives the hosting.
 *
 * `onOneServer` rather than `withoutOverlapping`: this is a once-a-day sweep, and the failure
 * to prevent is two servers each texting everybody, not one slow run lapping itself.
 */
Schedule::command('orders:remind-collection')
    ->dailyAt('17:00')
    ->timezone(config('app.timezone'))
    ->onOneServer()
    ->runInBackground();
