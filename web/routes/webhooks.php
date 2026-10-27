<?php

use App\Http\Controllers\GooglePlayWebhookController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Stores telling us about subscriptions: outside every session, no cookies, no CSRF token. Each one proves itself
| (Google signs every push). Loaded under /webhooks with only a rate limit (see bootstrap/app.php).
*/

Route::post('/google-play', GooglePlayWebhookController::class)->name('webhooks.google-play');
Route::post('/stripe', StripeWebhookController::class)->name('webhooks.stripe');
