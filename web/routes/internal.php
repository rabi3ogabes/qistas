<?php

use App\Http\Controllers\CronController;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine endpoints, outside every session: no cookies, no CSRF token, no signed-in person. Each one guards
| itself with a secret. Loaded under /internal with only a rate limit (see bootstrap/app.php).
*/

// GET because Vercel Cron sends GET; POST for every other pinger. Both need the same Bearer secret.
Route::match(['GET', 'POST'], '/cron', CronController::class)->name('internal.cron');
