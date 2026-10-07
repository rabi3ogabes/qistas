<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // After the session starts, so a remembered or saved language can be read.
        $middleware->web(append: [SetLocale::class]);

        // Signed-in people who open a guest page (sign-in, sign-up) go to the app, not to the site root.
        $middleware->redirectUsersTo(fn () => config('fortify.home'));

        $middleware->alias([
            'tenant' => SetCurrentTenant::class,
            'account.active' => EnsureAccountActive::class,
            'feature' => EnsureFeature::class,
        ]);

        // Everything under /admin: signed-in, not suspended, platform admin (else 404), second factor confirmed.
        $middleware->group('admin', [
            EnsureAccountActive::class,
            EnsurePlatformAdmin::class,
            RequireTwoFactorForAdmins::class,
        ]);

        // The workspace must be known before route-model binding, or another workspace's ids would resolve.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
