<?php

use App\Http\Controllers\SchedulePreviewController;
use App\Http\Controllers\Workspace\AccountDeletionController;
use App\Http\Controllers\Workspace\ActivityController;
use App\Http\Controllers\Workspace\BackupController;
use App\Http\Controllers\Workspace\BillingController;
use App\Http\Controllers\Workspace\BusinessProfileController;
use App\Http\Controllers\Workspace\ContractController;
use App\Http\Controllers\Workspace\CustomerController;
use App\Http\Controllers\Workspace\DashboardController;
use App\Http\Controllers\Workspace\DocumentController;
use App\Http\Controllers\Workspace\FileController;
use App\Http\Controllers\Workspace\InvestorController;
use App\Http\Controllers\Workspace\PaymentController;
use App\Http\Controllers\Workspace\ProductController;
use App\Http\Controllers\Workspace\SecurityPolicyController;
use App\Http\Controllers\Workspace\TagController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\Workspace\ToolsController;
use Illuminate\Support\Facades\Route;

// Everything here is /app/..., named app.*, and runs for a signed-in member of an active workspace.
Route::get('/', DashboardController::class)->name('dashboard');

Route::get('billing', BillingController::class)->name('billing');

// Delete my account: the owner deletes the business after 30 days (and can restore it until then); anyone else their login.
Route::get('account/delete', [AccountDeletionController::class, 'show'])->name('account.delete.show');
Route::post('account/delete', [AccountDeletionController::class, 'store'])->middleware('throttle:account-deletion')->name('account.delete.store');
Route::delete('account/delete', [AccountDeletionController::class, 'destroy'])->name('account.delete.destroy');

// The team: the people, their roles and invitations by link (Win Plan PP11).
Route::middleware('feature:members')->group(function (): void {
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team/invitations', [TeamController::class, 'invite'])->name('team.invitations.store');
    Route::delete('team/invitations/{invitation}', [TeamController::class, 'revoke'])->whereUuid('invitation')->name('team.invitations.destroy');
    Route::put('team/members/{member}', [TeamController::class, 'update'])->whereUuid('member')->name('team.members.update');
    Route::delete('team/members/{member}', [TeamController::class, 'remove'])->whereUuid('member')->name('team.members.destroy');
});

// Who funds the business (Win Plan PP3). Reading stays open when the feature is off; writing asks for it.
Route::get('investors', [InvestorController::class, 'index'])->name('investors.index');
Route::get('investors/create', [InvestorController::class, 'create'])->name('investors.create');
Route::post('investors', [InvestorController::class, 'store'])->name('investors.store');
Route::get('investors/{investor}', [InvestorController::class, 'show'])->whereUuid('investor')->name('investors.show');
Route::put('investors/{investor}', [InvestorController::class, 'update'])->whereUuid('investor')->name('investors.update');
Route::post('investors/{investor}/entries', [InvestorController::class, 'storeEntry'])->whereUuid('investor')->name('investors.entries.store');
Route::post('investor-entries/{entry}/reverse', [InvestorController::class, 'reverse'])->whereUuid('entry')->name('investor-entries.reverse');

// The products list to pick from when opening a contract (Win Plan PP7).
Route::get('products', [ProductController::class, 'index'])->name('products.index');
Route::post('products', [ProductController::class, 'store'])->name('products.store');
Route::put('products/{product}', [ProductController::class, 'update'])->whereUuid('product')->name('products.update');

// A stored file (an ID photo, a proof of payment): a member whose role may see it is sent to a link that expires in minutes.
Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');

// The settings of the features that are on for this workspace (empty until a feature declares one).
Route::get('settings/tools', [ToolsController::class, 'show'])->name('settings.tools');
// Backups and data, and the activity log (Win Plan PP10).
Route::get('settings/backups', [BackupController::class, 'show'])->name('settings.backups');
Route::post('exports', [BackupController::class, 'store'])->middleware('throttle:documents')->name('exports.store');
Route::get('exports/{export}/download', [BackupController::class, 'download'])->whereUuid('export')->name('exports.download');
Route::get('activity', ActivityController::class)->name('activity');

// Who the documents come from, and how they look by default (Win Plan PP8).
Route::get('settings/business', [BusinessProfileController::class, 'edit'])->name('settings.business');
Route::put('settings/business', [BusinessProfileController::class, 'update'])->name('settings.business.update');
Route::post('settings/business/{slot}', [BusinessProfileController::class, 'storeAsset'])->whereIn('slot', ['logo', 'signature'])->name('settings.business.assets.store');
Route::delete('settings/business/{slot}', [BusinessProfileController::class, 'destroyAsset'])->whereIn('slot', ['logo', 'signature'])->name('settings.business.assets.destroy');
Route::put('settings/documents', [BusinessProfileController::class, 'updatePreferences'])->name('settings.documents.update');
Route::put('settings/tools/{key}', [ToolsController::class, 'update'])->name('settings.tools.update');
Route::put('settings/security', [SecurityPolicyController::class, 'update'])->name('settings.security');

// Statements, reports and receipts as PDF files (Win Plan PP8).
Route::middleware('throttle:documents')->group(function (): void {
    Route::get('customers/{customer}/statement', [DocumentController::class, 'customerStatement'])->name('customers.statement');
    Route::get('contracts/{contract}/statement', [DocumentController::class, 'contractStatement'])->name('contracts.statement');
    Route::get('payments/{transaction}/receipt', [DocumentController::class, 'receipt'])->name('payments.receipt');
    Route::get('reports/transactions', [DocumentController::class, 'transactions'])->name('reports.transactions');
    Route::get('investors/{investor}/report', [DocumentController::class, 'investorReport'])->whereUuid('investor')->name('investors.report');
});

Route::resource('customers', CustomerController::class);
// Lists that stay short (Win Plan PP12): pins, tags, archived contracts.
Route::post('customers/{customer}/pin', [CustomerController::class, 'pin'])->name('customers.pin');
Route::delete('customers/{customer}/pin', [CustomerController::class, 'unpin'])->name('customers.unpin');
Route::get('tags', [TagController::class, 'index'])->name('tags.index');
Route::post('tags', [TagController::class, 'store'])->name('tags.store');
Route::put('tags/{tag}', [TagController::class, 'update'])->whereUuid('tag')->name('tags.update');
Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereUuid('tag')->name('tags.destroy');
Route::post('contracts/{contract}/archive', [ContractController::class, 'archive'])->name('contracts.archive');
Route::post('contracts/{contract}/unarchive', [ContractController::class, 'unarchive'])->name('contracts.unarchive');

// The same engine that writes real contracts also answers the form's live preview.
Route::post('contracts/preview', SchedulePreviewController::class)->middleware('throttle:contract-preview')->name('contracts.preview');
Route::resource('contracts', ContractController::class)->only(['index', 'create', 'store', 'show']);
Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
// Open contracts (Win Plan PP4): "they took", and turning a scheduled or cash contract into an open one.
Route::post('contracts/{contract}/charges', [ContractController::class, 'charge'])->name('contracts.charges.store');
Route::post('contracts/{contract}/convert-to-open', [ContractController::class, 'convert'])->name('contracts.convert');

Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
Route::post('contracts/{contract}/payments', [PaymentController::class, 'store'])->name('contracts.payments.store');
Route::post('payments/{transaction}/void', [PaymentController::class, 'void'])->name('payments.void');
