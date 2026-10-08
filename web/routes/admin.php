<?php

use App\Http\Controllers\Admin\DemoAccountsController;
use App\Http\Controllers\Admin\FeaturesController;
use App\Http\Controllers\Admin\HomeController;
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
