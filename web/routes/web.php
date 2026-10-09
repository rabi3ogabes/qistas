<?php

use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SchedulePreviewController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\ThemeCssController;
use Illuminate\Support\Facades\Route;

// The public website.
Route::get('/', [SiteController::class, 'home'])->name('home');
Route::get('/pricing', [SiteController::class, 'pricing'])->name('pricing');
Route::get('/terms', [SiteController::class, 'terms'])->name('terms');
Route::get('/privacy', [SiteController::class, 'privacy'])->name('privacy');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

// The colours chosen in the admin, as CSS variables (linked by every page of the website and the web app).
Route::get('/theme.css', ThemeCssController::class)->name('theme.css');

// A brand picture chosen in the admin: its address never changes, so it can be cached for good.
Route::get('/brand-assets/{asset}', BrandAssetController::class)->whereUuid('asset')->name('brand.asset');
Route::post('/schedule-preview', SchedulePreviewController::class)->middleware('throttle:schedule-preview')->name('schedule.preview');

// "Try the demo" (off unless config qistas.demo_login.enabled): a throw-away account per press, rate limited.
Route::post('/demo/{persona}', [DemoLoginController::class, 'start'])->whereIn('persona', ['admin', 'user'])->middleware(['guest', 'throttle:demo'])->name('demo.start');
Route::post('/demo-leave', [DemoLoginController::class, 'leave'])->middleware('auth')->name('demo.leave');

Route::view('/account/suspended', 'account.suspended')->name('account.suspended');

// Sudo mode: the security area asks for the password again (valid for config('auth.password_timeout')).
Route::middleware(['auth', 'account.active', 'password.confirm'])->prefix('account')->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::get('/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.recovery-codes');
});

// Unknown addresses go through the web group too, so the not-found page speaks the reader's language.
Route::fallback(fn () => abort(404));
