<?php

use App\Http\Controllers\SchedulePreviewController;
use App\Http\Controllers\Workspace\AccountDeletionController;
use App\Http\Controllers\Workspace\BillingController;
use App\Http\Controllers\Workspace\ContractController;
use App\Http\Controllers\Workspace\CustomerController;
use App\Http\Controllers\Workspace\DashboardController;
use App\Http\Controllers\Workspace\FileController;
use App\Http\Controllers\Workspace\PaymentController;
use App\Http\Controllers\Workspace\SecurityPolicyController;
use App\Http\Controllers\Workspace\ToolsController;
use Illuminate\Support\Facades\Route;

// Everything here is /app/..., named app.*, and runs for a signed-in member of an active workspace.
Route::get('/', DashboardController::class)->name('dashboard');

Route::get('billing', BillingController::class)->name('billing');

// Delete my account: the owner deletes the business after 30 days (and can restore it until then); anyone else their login.
Route::get('account/delete', [AccountDeletionController::class, 'show'])->name('account.delete.show');
Route::post('account/delete', [AccountDeletionController::class, 'store'])->middleware('throttle:account-deletion')->name('account.delete.store');
Route::delete('account/delete', [AccountDeletionController::class, 'destroy'])->name('account.delete.destroy');

// A stored file (an ID photo, a proof of payment): a member whose role may see it is sent to a link that expires in minutes.
Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');

// The settings of the features that are on for this workspace (empty until a feature declares one).
Route::get('settings/tools', [ToolsController::class, 'show'])->name('settings.tools');
Route::put('settings/tools/{key}', [ToolsController::class, 'update'])->name('settings.tools.update');
Route::put('settings/security', [SecurityPolicyController::class, 'update'])->name('settings.security');

Route::resource('customers', CustomerController::class);

// The same engine that writes real contracts also answers the form's live preview.
Route::post('contracts/preview', SchedulePreviewController::class)->middleware('throttle:contract-preview')->name('contracts.preview');
Route::resource('contracts', ContractController::class)->only(['index', 'create', 'store', 'show']);
Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');

Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::post('contracts/{contract}/payments', [PaymentController::class, 'store'])->name('contracts.payments.store');
Route::post('payments/{transaction}/void', [PaymentController::class, 'void'])->name('payments.void');
