<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Businesses whose owners deleted them more than 30 days ago are erased (the owner can restore until then).
Schedule::command('qistas:purge-deleted-accounts')->daily()->withoutOverlapping(30);

// Every business's nightly copy (Win Plan PP10), a few businesses an hour so no run is long.
Schedule::command('qistas:export-workspaces')->hourly()->withoutOverlapping(30);

// The morning summary and the instalment alerts (Win Plan PP9): each person is told at their own time, once.
Schedule::command('qistas:send-digests')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('qistas:instalment-alerts')->everyFiveMinutes()->withoutOverlapping(10);

// The home-screen widget (daily-habit wave A, H3): one silent push a day at each person's summary time keeps it fresh.
Schedule::command('qistas:send-glance-refresh')->everyFiveMinutes()->withoutOverlapping(10);

// The evening reminder to close the day (daily-habit wave A, H4): from each workspace's closing time, for up to two hours (never past midnight), once a day per person.
Schedule::command('qistas:send-day-close-reminders')->everyFiveMinutes()->withoutOverlapping(10);

// Each customer's usual payday (daily-habit wave A, H6): worked out again every night for the workspaces that have it on; a payment or a void also updates it at once.
Schedule::command('qistas:learn-paydays')->daily()->withoutOverlapping(30);

// Late fees (Win Plan K4): each workspace soon after its own midnight, once a day; a missed run is caught up next time.
Schedule::command('qistas:charge-late-fees')->everyFiveMinutes()->withoutOverlapping(10);

// Owners are told before a plan they pay for ends without renewing, and once it has ended (Sprint 3 Task 1b).
Schedule::command('qistas:billing-notices')->hourly()->withoutOverlapping(10);

// Each person's sign-in record is kept for qistas.sign_in_days (90 by default), then removed; nothing else removes it.
Schedule::command('qistas:sign-ins:prune')->daily()->withoutOverlapping(30);

// The error center forgets old samples, issues and scanner-noise counts every night, and clears its expired rows from the cache table.
Schedule::command('qistas:errors:prune')->daily()->withoutOverlapping(30);

// Errors that waited in storage/logs/error-spool.ndjson while the database was away are recorded as soon as it answers again.
Schedule::command('qistas:errors:import-spool')->everyFiveMinutes()->withoutOverlapping(10);

// The error digest goes at 04:00 on the app's clock, which is fixed to UTC in config/app.php, so at 07:00 in the Gulf.
Schedule::command('qistas:errors:digest')->dailyAt('04:00')->withoutOverlapping(30);
