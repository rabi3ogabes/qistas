<?php

use App\Http\Controllers\Admin\DemoAccountsController;
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
