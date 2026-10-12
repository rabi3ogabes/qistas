<?php

use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\DemoLoginController;
use App\Http\Controllers\DocumentVerificationController;
use App\Http\Controllers\ExportFileController;
use App\Http\Controllers\FileBlobController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SchedulePreviewController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SignController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\ThemeCssController;
use App\Site\SitePages;
use Illuminate\Support\Facades\Route;

// The public website (Sprint 2, D10): every page at its plain address, where the reader's own choice sets the language,
// and again under each language's path (/ar/pricing), which always reads in that language. See App\Site\SitePages.
$site = function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('/pricing', [SiteController::class, 'pricing'])->name('pricing');
    // The instalment calculator for search (Win Plan PP15): the plan is in the address, so a link can be shared.
    Route::get('/installment-calculator', [SiteController::class, 'calculator'])->name('calculator');
    // For a shop moving to Qistas from a notebook, a spreadsheet or another app.
    Route::get('/switch/notebook', [SiteController::class, 'fromNotebook'])->name('switch.notebook');
    Route::get('/switch/excel', [SiteController::class, 'fromExcel'])->name('switch.excel');
    Route::get('/switch/another-app', [SiteController::class, 'fromAnotherApp'])->name('switch.app');
    // Whether Qistas answers right now and when scheduled work last ran; nothing private.
    Route::get('/status', StatusController::class)->name('status');
    Route::get('/terms', [SiteController::class, 'terms'])->name('terms');
    Route::get('/privacy', [SiteController::class, 'privacy'])->name('privacy');
    // How to delete an account, for anyone (Google Play asks for a page like this).
    Route::get('/account/delete', [SiteController::class, 'accountDeletion'])->name('account.delete.info');
};
$site();
Route::prefix('{locale}')->where(['locale' => implode('|', config('qistas.locales'))])->name(SitePages::PREFIX)->group($site);

// An invitation link into a business: anyone may open it; joining needs a login, which can be made right there.
// A customer signs a contract on their own phone (Win Plan K5): the code first, then the signature, once.
Route::get('/sign/{token}', [SignController::class, 'show'])->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:60,1')->name('sign.show');
Route::post('/sign/{token}/code', [SignController::class, 'code'])->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:10,1')->name('sign.code');
Route::post('/sign/{token}', [SignController::class, 'sign'])->where('token', '[A-Za-z0-9]{20,100}')->middleware('throttle:10,1')->name('sign.store');
// A file of the private disk kept in the database (Win Plan D5), through a link that expires within five minutes.
Route::get('/files/blob/{path}', FileBlobController::class)->where('path', 'tenants/[A-Za-z0-9/_.\-]+')->middleware(['signed', 'throttle:120,1'])->name('files.blob');
Route::get('/exports/{export}/file', [ExportFileController::class, 'show'])->whereUuid('export')->middleware(['signed', 'throttle:30,1'])->name('exports.file');
Route::get('/verify/{code}', [DocumentVerificationController::class, 'show'])->where('code', '[A-Za-z0-9]{6,16}')->middleware('throttle:verify')->name('documents.verify');
Route::get('/invite/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{20,100}')->name('invitation.show');
Route::post('/invite/{token}/accept', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{20,100}')->middleware(['auth', 'account.active', 'throttle:10,1'])->name('invitation.accept');
Route::post('/invite/{token}/register', [InvitationController::class, 'register'])->where('token', '[A-Za-z0-9]{20,100}')->middleware(['guest', 'throttle:10,1'])->name('invitation.register');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
// The web app, installable from the browser (Win Plan PL04).
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

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
    Route::delete('/security/devices/{device}', [SecurityController::class, 'signOutDevice'])->name('security.devices.destroy');
});

// Unknown addresses go through the web group too, so the not-found page speaks the reader's language.
Route::fallback(fn () => abort(404));
