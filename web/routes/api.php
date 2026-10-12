<?php

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AppearanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\ContractPhotoController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerPaymentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DemoController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\GuarantorController;
use App\Http\Controllers\Api\V1\InvestorController;
use App\Http\Controllers\Api\V1\LateFeeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\ReleaseNoteController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SchedulePreviewController;
use App\Http\Controllers\Api\V1\SignatureController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\ToolsController;
use App\Http\Controllers\Api\V1\WorkspaceSecurityController;
use Illuminate\Support\Facades\Route;

/*
| The REST API, /api/v1. Stateless JSON, bearer tokens only (see docs/api/openapi.yaml). Every failure has the same
| shape (App\Http\ApiErrors); a plan or limit failure is HTTP 402 (App\Entitlements\EntitlementException).
|
| Public: the plans on offer, creating an account, signing in. Everything else needs a token, an account that is
| not suspended, and resolves the workspace from the token's owner, never from anything the client sends.
*/

Route::name('api.')->group(function (): void {
    Route::get('plans', [PlanController::class, 'index'])->middleware('throttle:api-public')->name('plans');
    Route::get('appearance', AppearanceController::class)->middleware('throttle:api-public')->name('appearance');
    Route::get('demo', [DemoController::class, 'index'])->middleware('throttle:api-public')->name('demo');
    Route::post('demo/{persona}', [DemoController::class, 'start'])->whereIn('persona', ['admin', 'user'])->middleware('throttle:api-demo')->name('demo.start');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:api-register')->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'account.active', 'tenant', 'deleting', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll'])->name('auth.logout-all');

        Route::get('me', MeController::class)->name('me');

        // Buy Pro your way (Win Plan PP13): what the workspace is on, and a Google Play purchase checked with Google.
        Route::get('billing', [BillingController::class, 'show'])->name('billing.show');
        Route::post('billing/play/verify', [BillingController::class, 'verifyPlay'])->middleware('throttle:api-money')->name('billing.play.verify');

        // Help inside the app (Win Plan PP17): how to reach the team, the person's questions with the replies, and what's new.
        Route::get('support', [SupportController::class, 'info'])->name('support.info');
        Route::get('support/tickets', [SupportController::class, 'index'])->name('support.tickets.index');
        Route::post('support/tickets', [SupportController::class, 'store'])->middleware('throttle:10,60')->name('support.tickets.store');
        Route::get('release-notes', ReleaseNoteController::class)->name('release-notes');
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('settings/tools', [ToolsController::class, 'index'])->name('settings.tools.index');
        Route::put('settings/tools/{key}', [ToolsController::class, 'update'])->name('settings.tools.update');
        Route::put('workspace/security', [WorkspaceSecurityController::class, 'update'])->name('workspace.security');

        // Delete my account (store requirement): the owner deletes the business after 30 days, anyone else their login.
        Route::post('account/deletion', [AccountDeletionController::class, 'store'])->middleware('throttle:account-deletion')->name('account.deletion.store');
        Route::delete('account/deletion', [AccountDeletionController::class, 'destroy'])->name('account.deletion.destroy');

        // The team (Win Plan PP11): who is in the business, invitations by link, roles, removing people.
        Route::middleware('feature:members')->group(function (): void {
            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::post('team/invitations', [TeamController::class, 'invite'])->middleware('throttle:api-money')->name('team.invitations.store');
            Route::delete('team/invitations/{invitation}', [TeamController::class, 'revoke'])->whereUuid('invitation')->name('team.invitations.destroy');
            Route::put('team/members/{member}', [TeamController::class, 'update'])->whereUuid('member')->name('team.members.update');
            Route::delete('team/members/{member}', [TeamController::class, 'remove'])->whereUuid('member')->name('team.members.destroy');
        });
        Route::post('invitations/accept', [TeamController::class, 'accept'])->middleware('throttle:api-money')->name('invitations.accept');

        // Who funds the business (Win Plan PP3). Reading stays open when the feature is off; writing asks for it.
        Route::get('investors', [InvestorController::class, 'index'])->name('investors.index');
        Route::get('investors/{investor}', [InvestorController::class, 'show'])->whereUuid('investor')->name('investors.show');
        Route::middleware('throttle:api-money')->group(function (): void {
            Route::post('investors', [InvestorController::class, 'store'])->name('investors.store');
            Route::put('investors/{investor}', [InvestorController::class, 'update'])->whereUuid('investor')->name('investors.update');
            Route::post('investors/{investor}/entries', [InvestorController::class, 'storeEntry'])->whereUuid('investor')->name('investors.entries.store');
            Route::post('investor-entries/{entry}/reverse', [InvestorController::class, 'reverse'])->whereUuid('entry')->name('investor-entries.reverse');
        });

        // The products list to pick from when opening a contract (Win Plan PP7).
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])->whereUuid('product')->name('products.update');

        // Statements, reports and receipts as PDF files, and who they come from (Win Plan PP8).
        Route::middleware('throttle:documents')->group(function (): void {
            Route::get('customers/{customer}/statement.pdf', [DocumentController::class, 'customerStatement'])->whereUuid('customer')->name('customers.statement');
            Route::get('contracts/{contract}/statement.pdf', [DocumentController::class, 'contractStatement'])->whereUuid('contract')->name('contracts.statement');
            Route::get('contracts/{contract}/agreement.pdf', [DocumentController::class, 'contractAgreement'])->whereUuid('contract')->name('contracts.agreement');
            Route::get('contracts/{contract}/promissory-note.pdf', [DocumentController::class, 'promissoryNote'])->whereUuid('contract')->name('contracts.promissory-note');
            Route::get('payments/{transaction}/receipt.pdf', [DocumentController::class, 'receipt'])->whereUuid('transaction')->name('payments.receipt');
            Route::get('reports/transactions.pdf', [DocumentController::class, 'transactions'])->name('reports.transactions');
            Route::get('investors/{investor}/report.pdf', [DocumentController::class, 'investorReport'])->whereUuid('investor')->name('investors.report');
        });
        // Backups and data, and the activity log (Win Plan PP10).
        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('exports', [BackupController::class, 'store'])->middleware('throttle:documents')->name('exports.store');
        Route::get('exports/{export}', [BackupController::class, 'show'])->whereUuid('export')->name('exports.show');
        Route::get('exports/{export}/download', [BackupController::class, 'download'])->whereUuid('export')->name('exports.download');
        Route::get('activity', ActivityController::class)->name('activity');
        Route::get('settings/business-profile', [BusinessProfileController::class, 'show'])->name('settings.business-profile.show');
        Route::put('settings/business-profile', [BusinessProfileController::class, 'update'])->name('settings.business-profile.update');
        Route::post('settings/business-profile/{slot}', [BusinessProfileController::class, 'storeAsset'])->whereIn('slot', ['logo', 'signature'])->name('settings.business-profile.assets.store');
        Route::delete('settings/business-profile/{slot}', [BusinessProfileController::class, 'destroyAsset'])->whereIn('slot', ['logo', 'signature'])->name('settings.business-profile.assets.destroy');
        Route::get('settings/documents', [BusinessProfileController::class, 'preferences'])->name('settings.documents.show');
        Route::put('settings/documents', [BusinessProfileController::class, 'updatePreferences'])->name('settings.documents.update');

        Route::apiResource('customers', CustomerController::class);
        // Lists that stay short (Win Plan PP12): pins, tags, archived contracts.
        Route::post('customers/{customer}/pin', [CustomerController::class, 'pin'])->whereUuid('customer')->name('customers.pin');
        Route::delete('customers/{customer}/pin', [CustomerController::class, 'unpin'])->whereUuid('customer')->name('customers.unpin');
        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::put('tags/{tag}', [TagController::class, 'update'])->whereUuid('tag')->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereUuid('tag')->name('tags.destroy');
        Route::post('contracts/{contract}/archive', [ContractController::class, 'archive'])->whereUuid('contract')->name('contracts.archive');
        Route::post('contracts/{contract}/unarchive', [ContractController::class, 'unarchive'])->whereUuid('contract')->name('contracts.unarchive');

        // Remind everyone due today, with the business's own wording (Win Plan PP9).
        Route::get('reminders/due-today', [ReminderController::class, 'dueToday'])->name('reminders.due-today');
        Route::get('message-templates', [ReminderController::class, 'templates'])->name('message-templates.index');
        Route::put('message-templates/{key}', [ReminderController::class, 'updateTemplate'])->name('message-templates.update');

        // Settle early, change the plan, pay across contracts (Win Plan PP6).
        Route::get('contracts/{contract}/early-settlement', [ContractController::class, 'earlySettlement'])->whereUuid('contract')->name('contracts.early-settlement.show');
        Route::post('contracts/{contract}/early-settlement', [ContractController::class, 'settleEarly'])->whereUuid('contract')->middleware('throttle:api-money')->name('contracts.early-settlement.store');
        Route::post('contracts/{contract}/amendments/preview', [ContractController::class, 'previewAmendment'])->whereUuid('contract')->name('contracts.amendments.preview');
        Route::post('contracts/{contract}/amendments', [ContractController::class, 'amend'])->whereUuid('contract')->name('contracts.amendments.store');
        Route::post('customers/{customer}/payments', CustomerPaymentController::class)->whereUuid('customer')->middleware('throttle:api-money')->name('customers.payments.store');

        Route::post('contracts/preview', SchedulePreviewController::class)->name('contracts.preview');
        Route::apiResource('contracts', ContractController::class)->only(['index', 'store', 'show']);
        Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
        // Open contracts (Win Plan PP4): "they took" lines, and turning a scheduled or cash contract into an open one.
        Route::post('contracts/{contract}/charges', [ContractController::class, 'charge'])->middleware('throttle:api-money')->name('contracts.charges.store');
        Route::post('contracts/{contract}/convert-to-open', [ContractController::class, 'convert'])->name('contracts.convert');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        // What a payment would cover, worked out by the allocator and never written (Win Plan PP16).
        Route::post('contracts/{contract}/payments/preview', [PaymentController::class, 'preview'])->name('contracts.payments.preview');
        Route::post('contracts/{contract}/payments', [PaymentController::class, 'store'])->middleware('throttle:api-money')->name('contracts.payments.store');
        Route::post('payments/{transaction}/void', [PaymentController::class, 'void'])->middleware('throttle:api-money')->name('payments.void');
        // Late fees (Win Plan K4): listed with their contract; an owner or manager waives one with a reason.
        // Reports (Win Plan K8, RP14, RP15, CT27): Pro, for everyone but collectors.
        Route::get('reports/forecast', [ReportController::class, 'forecast'])->name('reports.forecast');
        Route::get('reports/ageing', [ReportController::class, 'ageing'])->name('reports.ageing');
        Route::get('reports/profit', [ReportController::class, 'profit'])->name('reports.profit');
        Route::post('late-fees/{fee}/waive', [LateFeeController::class, 'waive'])->whereUuid('fee')->middleware('throttle:api-money')->name('late-fees.waive');
        // Paperwork (Win Plan K5): guarantors and photos, listed with the contract; one stored file's five-minute link.
        Route::post('contracts/{contract}/guarantors', [GuarantorController::class, 'store'])->whereUuid('contract')->name('contracts.guarantors.store');
        Route::put('guarantors/{guarantor}', [GuarantorController::class, 'update'])->whereUuid('guarantor')->name('guarantors.update');
        Route::delete('guarantors/{guarantor}', [GuarantorController::class, 'destroy'])->whereUuid('guarantor')->name('guarantors.destroy');
        Route::post('contracts/{contract}/photos', [ContractPhotoController::class, 'store'])->whereUuid('contract')->middleware('throttle:documents')->name('contracts.photos.store');
        Route::delete('contracts/{contract}/photos/{photo}', [ContractPhotoController::class, 'destroy'])->whereUuid(['contract', 'photo'])->name('contracts.photos.destroy');
        Route::get('files/{file}', [FileController::class, 'show'])->whereUuid('file')->name('files.show');
        // The customer's signature (Win Plan K5): drawn on this phone, or a link with a code for theirs.
        Route::post('contracts/{contract}/signature', [SignatureController::class, 'store'])->whereUuid('contract')->middleware('throttle:documents')->name('contracts.signature.store');
        Route::post('contracts/{contract}/signature-links', [SignatureController::class, 'link'])->whereUuid('contract')->middleware('throttle:documents')->name('contracts.signature-links.store');

        // Making and revoking API access tokens needs a signed-in device (an "app" token), not another API token.
        Route::middleware('abilities:app')->group(function (): void {
            Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
            Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
            Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
            // The phones and browsers signed in to my account, and signing one out (Win Plan PP16).
            Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
            Route::delete('devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');
            // Pushes, each person's alert choices and their inbox (Win Plan PP9).
            Route::post('push-tokens', [PushTokenController::class, 'store'])->name('push-tokens.store');
            Route::delete('push-tokens/{token}', [PushTokenController::class, 'destroy'])->where('token', '[^/]+')->name('push-tokens.destroy');
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
            Route::get('notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences.show');
            Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');
        });
    });
});
