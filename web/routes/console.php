<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Throw-away demo accounts are also cleared whenever someone presses a demo button; this catches a quiet site.
Schedule::command('qistas:prune-demo')->everyFifteenMinutes()->withoutOverlapping(10);
