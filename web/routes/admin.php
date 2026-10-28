<?php

use App\Http\Controllers\Admin\AiController;
use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\AppearanceEventController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\CardSettingsController;
use App\Http\Controllers\Admin\DemoAccountsController;
use App\Http\Controllers\Admin\FeaturesController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\ReleaseNoteController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\TeamSecurityController;
use App\Http\Controllers\Admin\TestWorkspaceController;
use Illuminate\Support\Facades\Route;

// Loaded under /admin with the "admin" middleware group (bootstrap/app.php): signed in, not suspended, platform
// staff (anyone else gets a 404), second factor confirmed.

Route::get('/', [HomeController::class, 'show'])->name('home');

Route::prefix('test')->name('test.')->controller(TestWorkspaceController::class)->group(function () {
    Route::post('/open', 'open')->name('open');
    Route::post('/reset', 'reset')->name('reset');
    Route::post('/leave', 'leave')->name('leave');
    Route::post('/plan', 'plan')->name('plan');
});

Route::post('/demo/prune', [DemoAccountsController::class, 'prune'])->name('demo.prune');

// Every real business, its owner and plan; a super admin puts one on another plan (with a reason, audited).
Route::prefix('businesses')->name('businesses.')->controller(BusinessController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('{tenant}', 'show')->whereUuid('tenant')->name('show');
    Route::put('{tenant}/plan', 'changePlan')->whereUuid('tenant')->middleware('throttle:30,1')->name('plan');
    Route::post('{tenant}/card/cancel', 'cancelCard')->whereUuid('tenant')->middleware('throttle:30,1')->name('card.cancel');
});

// Support (Win Plan PP17): the ticket queue and the support WhatsApp number; what's new in each app version.
Route::prefix('support')->name('support.')->controller(SupportController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('tickets/{ticket}/reply', 'reply')->whereUuid('ticket')->name('reply');
        Route::post('tickets/{ticket}/close', 'close')->whereUuid('ticket')->name('close');
        Route::put('settings', 'settings')->name('settings');
    });
});
Route::get('release-notes', [ReleaseNoteController::class, 'index'])->name('release-notes.index');
// Reading notebook photos and PDFs (Win Plan K9): the Anthropic key, never shown back.
Route::get('ai', [AiController::class, 'index'])->name('ai.index');
Route::put('ai', [AiController::class, 'update'])->middleware('throttle:30,1')->name('ai.update');
// E-mail through the owner's own SMTP server (Sprint 3, D1): the password never shown back.
Route::get('mail', [MailController::class, 'index'])->name('mail.index');
Route::put('mail', [MailController::class, 'update'])->middleware('throttle:30,1')->name('mail.update');
Route::post('mail/test', [MailController::class, 'test'])->middleware('throttle:10,1')->name('mail.test');
// Team security: whether the admin team must use two-factor (optional until a super admin requires it), and who does.
Route::get('security', [TeamSecurityController::class, 'index'])->name('security.index');
Route::put('security', [TeamSecurityController::class, 'update'])->middleware('throttle:30,1')->name('security.update');
// Notifications for everyone (Sprint 3 Task 1b): what may go where, and the Firebase key for pushes.
Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');
Route::put('notifications', [NotificationsController::class, 'update'])->middleware('throttle:30,1')->name('notifications.update');
Route::put('notifications/push', [NotificationsController::class, 'push'])->middleware('throttle:30,1')->name('notifications.push');
Route::post('release-notes', [ReleaseNoteController::class, 'store'])->middleware('throttle:60,1')->name('release-notes.store');

// Billing (Win Plan PP13): bank transfers waiting for a decision, with their receipts, and the billing settings. Any
// platform staff may look; a super admin decides and changes settings ('manage-plans', checked in the controller).
Route::prefix('billing')->name('billing.')->controller(BillingController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('requests/{paymentRequest}/receipt', 'receipt')->whereUuid('paymentRequest')->name('receipt');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('requests/{paymentRequest}/approve', 'approve')->whereUuid('paymentRequest')->name('approve');
        Route::post('requests/{paymentRequest}/reject', 'reject')->whereUuid('paymentRequest')->name('reject');
        Route::put('settings', 'settings')->name('settings');
    });
});
// Card payments (spec 2026-10-27 §8): the Stripe keys, saved and connected in one step; Reconnect, Disconnect, options.
Route::prefix('billing/card')->name('billing.card.')->controller(CardSettingsController::class)->middleware('throttle:60,1')->group(function () {
    Route::put('keys', 'keys')->name('keys');
    Route::post('connect', 'connect')->name('connect');
    Route::post('disconnect', 'disconnect')->name('disconnect');
    Route::put('settings', 'settings')->name('settings');
});

// Feature control: what the platform has on, off or in beta. Anyone on the platform team may look; only a super admin
// changes (the 'manage-platform-features' gate, checked in the controller).
Route::prefix('features')->name('features.')->controller(FeaturesController::class)->group(function () {
    Route::get('/', 'index')->name('index');

    Route::middleware('throttle:60,1')->group(function () {
        Route::get('presets/{preset}/preview', 'presetPreview')->name('presets.preview');
        Route::post('presets/{preset}/apply', 'presetApply')->name('presets.apply');
        Route::post('pause-automation', 'pause')->name('pause');
        Route::put('{key}/state', 'state')->name('state');
        Route::put('{key}/plans/{plan}', 'plan')->name('plan');
        Route::post('{key}/beta', 'betaStore')->name('beta.store');
        Route::delete('{key}/beta/{override}', 'betaDestroy')->name('beta.destroy');
    });
});

// Appearance: the colours, pictures and welcome banners of the website, the web app and the Android app. Anyone on the
// platform team may look; only a super admin changes (the 'manage-appearance' gate, checked in the controller).
Route::prefix('appearance')->name('appearance.')->controller(AppearanceController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('palette', 'palette')->middleware('throttle:120,1')->name('palette');

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('draft', 'save')->name('draft');
        Route::post('publish', 'publish')->name('publish');
        Route::post('pictures/{slot}', 'picture')->where('slot', '[a-z_]+')->name('picture');
        Route::post('versions/{version}/restore', 'restore')->whereNumber('version')->name('restore');
        Route::post('reset', 'reset')->name('reset');
        Route::post('discard', 'discard')->name('discard');
    });

    // Event themes: a national day's or a season's look for some countries and some days.
    Route::get('look', [AppearanceEventController::class, 'look'])->middleware('throttle:120,1')->name('look');

    Route::prefix('events')->name('events.')->controller(AppearanceEventController::class)->group(function () {
        Route::get('new', 'create')->name('create');
        Route::get('{event}', 'edit')->whereUuid('event')->name('edit');

        Route::middleware('throttle:60,1')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('pictures/{slot}', 'picture')->where('slot', '[a-z_]+')->name('picture');
            Route::put('{event}', 'update')->whereUuid('event')->name('update');
            Route::post('{event}/stop', 'stop')->whereUuid('event')->name('stop');
            Route::delete('{event}', 'destroy')->whereUuid('event')->name('destroy');
        });
    });
});
