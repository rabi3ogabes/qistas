<?php

use App\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/account/suspended', 'account.suspended')->name('account.suspended');

// Sudo mode: the security area asks for the password again (valid for config('auth.password_timeout')).
Route::middleware(['auth', 'account.active', 'password.confirm'])->prefix('account')->group(function () {
    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::get('/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.recovery-codes');
});
