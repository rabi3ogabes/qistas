<?php

use App\Entitlements\EntitlementException;
use App\Errors\ErrorCapture;
use App\Http\ApiErrors;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\GuardClientReport;
use App\Http\Middleware\KeepStaffOutOfTwoFactor;
use App\Http\Middleware\PreventDuplicateSubmissions;
use App\Http\Middleware\ReadOnlyWhileDeleting;
use App\Http\Middleware\RecordErrorPages;
use App\Http\Middleware\ServerTiming;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
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
            Route::middleware(['web', 'auth', 'account.active', 'tenant', 'deleting'])
                ->prefix('app')->name('app.')
                ->group(base_path('routes/app.php'));

            // The admin console: platform staff only (a 404 for everyone else). It opens with the password alone.
            Route::middleware(['web', 'auth', 'admin'])
                ->prefix('admin')->name('admin.')
                ->group(base_path('routes/admin.php'));

            // Machine-to-machine endpoints (the cron call): no session, no cookies, no CSRF; each guards itself with a
            // secret, and the rate limit keeps the secret from being guessed at speed.
            Route::middleware('throttle:30,1')
                ->prefix('internal')
                ->group(base_path('routes/internal.php'));

            // Stores telling us about subscriptions (Google Play): no session either; every push is signed by Google.
            Route::middleware('throttle:120,1')
                ->prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // How long the server and the database took, on every answer (measured where the site runs).
        $middleware->prepend(ServerTiming::class);

        // The error pages visitors are shown, for the error center. First of all: middleware end in the order they are
        // listed, and this must end before InvokeDeferredCallbacks runs what is deferred for after the answer.
        $middleware->prepend(RecordErrorPages::class);

        // After the session starts, so a remembered or saved language can be read.
        $middleware->web(append: [SetLocale::class, PreventDuplicateSubmissions::class, KeepStaffOutOfTwoFactor::class]);

        // The API is stateless JSON: always JSON, and a language taken from the request itself.
        $middleware->api(prepend: [ForceJsonResponse::class, SetApiLocale::class]);

        // Signed-in people who open a guest page (sign-in, sign-up) go to the app, not to the site root.
        $middleware->redirectUsersTo(fn () => config('fortify.home'));

        $middleware->alias([
            'tenant' => SetCurrentTenant::class,
            'account.active' => EnsureAccountActive::class,
            'feature' => EnsureFeature::class,
            'abilities' => CheckAbilities::class,
            // A business being deleted is read-only for everyone in it until it is erased or restored.
            'deleting' => ReadOnlyWhileDeleting::class,
            // An add from the app carrying an Idempotency-Key is saved once however often it arrives.
            'once' => PreventDuplicateSubmissions::class,
        ]);

        // Everything under /admin: signed-in, not suspended, platform admin (else 404). No second factor: the team never uses one.
        $middleware->group('admin', [
            EnsureAccountActive::class,
            EnsurePlatformAdmin::class,
        ]);

        // The website's scripts report their errors to the error center without a session, so without a token. The route
        // also runs without the forgery check at all (routes/web.php); this keeps it exempt if it ever runs with it.
        $middleware->preventRequestForgery(except: ['client-errors']);

        // The two reporters measure a body before they read it: an oversized one is refused without the framework first
        // walking all of it to trim its strings and turn its empty ones into null (the validation copes with both).
        $reporter = fn (Request $request): bool => $request->is('client-errors', 'api/v1/client-errors');
        $middleware->trimStrings([$reporter]);
        $middleware->convertEmptyStringsToNull([$reporter]);

        // The reporters' guard (origin, size, type) runs before the throttle: the framework runs a throttle ahead of anything
        // it does not list by name, wherever a route writes it, and a refusal should cost the database nothing.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: GuardClientReport::class);

        // The workspace must be known before route-model binding, or another workspace's ids would resolve.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentTenant::class);
        // ...and a suspended account is told so before its workspace is looked for.
        $middleware->prependToPriorityList(before: SetCurrentTenant::class, prepend: EnsureAccountActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // "Not on your plan" and "switched off" are answers shown to the person, not failures for the error log.
        $exceptions->dontReport(EntitlementException::class);

        // Every exception that is reported (so never what dontReport leaves out) goes to the error center too, after the
        // answer; nothing is returned, so the framework still writes it to the log.
        $exceptions->report(fn (Throwable $e) => ErrorCapture::reported($e));

        // Keys and service accounts pasted into the admin area never go back into the session with a refused form.
        $exceptions->dontFlash(['secret_key', 'publishable_key', 'key', 'play_account', 'service_account']);

        // /api/ answers every failure in one shape (see ApiErrors); everything else keeps the framework's pages.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrors::render($e, $request));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
