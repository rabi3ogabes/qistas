<?php

use App\Http\Controllers\Api\V1\AccountDeletionController;
use App\Http\Controllers\Api\V1\AppearanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DemoController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SchedulePreviewController;
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

        Route::apiResource('customers', CustomerController::class);

        Route::post('contracts/preview', SchedulePreviewController::class)->name('contracts.preview');
        Route::apiResource('contracts', ContractController::class)->only(['index', 'store', 'show']);
        Route::post('contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('contracts/{contract}/payments', [PaymentController::class, 'store'])->middleware('throttle:api-money')->name('contracts.payments.store');
        Route::post('payments/{transaction}/void', [PaymentController::class, 'void'])->middleware('throttle:api-money')->name('payments.void');

        // Making and revoking API access tokens needs a signed-in device (an "app" token), not another API token.
        Route::middleware('abilities:app')->group(function (): void {
            Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
            Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
            Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
        });
    });
});
