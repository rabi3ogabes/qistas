<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Throw-away demo accounts are also cleared whenever someone presses a demo button; this catches a quiet site.
Schedule::command('qistas:prune-demo')->everyFifteenMinutes()->withoutOverlapping(10);

// Businesses whose owners deleted them more than 30 days ago are erased (the owner can restore until then).
Schedule::command('qistas:purge-deleted-accounts')->daily()->withoutOverlapping(30);

// Every business's nightly copy (Win Plan PP10), a few businesses an hour so no run is long.
Schedule::command('qistas:export-workspaces')->hourly()->withoutOverlapping(30);

// The morning summary and the instalment alerts (Win Plan PP9): each person is told at their own time, once.
Schedule::command('qistas:send-digests')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('qistas:instalment-alerts')->everyFiveMinutes()->withoutOverlapping(10);
