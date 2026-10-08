<?php

use App\Http\ApiErrors;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The signed-in app: a person with a workspace who is not suspended. The tenant middleware runs
            // before route-model binding, so another workspace's ids are simply not found.
            Route::middleware(['web', 'auth', 'account.active', 'tenant'])
                ->prefix('app')->name('app.')
                ->group(base_path('routes/app.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // After the session starts, so a remembered or saved language can be read.
        $middleware->web(append: [SetLocale::class]);

        // The API is stateless JSON: always JSON, and a language taken from the request itself.
        $middleware->api(prepend: [ForceJsonResponse::class, SetApiLocale::class]);

        // Signed-in people who open a guest page (sign-in, sign-up) go to the app, not to the site root.
        $middleware->redirectUsersTo(fn () => config('fortify.home'));

        $middleware->alias([
            'tenant' => SetCurrentTenant::class,
            'account.active' => EnsureAccountActive::class,
            'feature' => EnsureFeature::class,
            'abilities' => CheckAbilities::class,
        ]);

        // Everything under /admin: signed-in, not suspended, platform admin (else 404), second factor confirmed.
        $middleware->group('admin', [
            EnsureAccountActive::class,
            EnsurePlatformAdmin::class,
            RequireTwoFactorForAdmins::class,
        ]);

        // The workspace must be known before route-model binding, or another workspace's ids would resolve.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentTenant::class);
        // ...and a suspended account is told so before its workspace is looked for.
        $middleware->prependToPriorityList(before: SetCurrentTenant::class, prepend: EnsureAccountActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /api/ answers every failure in one shape (see ApiErrors); everything else keeps the framework's pages.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrors::render($e, $request));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
