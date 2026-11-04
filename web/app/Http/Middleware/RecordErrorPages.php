<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Entitlements\EntitlementException;
use App\Errors\ErrorCapture;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Tells the error center about the error pages visitors are shown (design section 3), once the answer is out. It does
 * nothing while a request is answered, and at the end of one below 403 (nearly all of them) it only reads the status.
 * ErrorCapture decides what of the rest is recorded and records it after the answer.
 *
 * It is the first of the global middleware (bootstrap/app.php), so it ends before InvokeDeferredCallbacks runs the work
 * deferred for after the answer, including what it hands to ErrorCapture.
 */
final class RecordErrorPages
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $status = $response->getStatusCode();

            if ($status < 403) {
                return;
            }

            // The exception the answer was made from, when the router made it from one (it says so on the response).
            $exception = property_exists($response, 'exception') && $response->exception instanceof Throwable ? $response->exception : null;

            // "Not on your plan" and "switched off" are answers for the person, whatever page shows them (spec 3).
            if ($exception instanceof EntitlementException) {
                return;
            }

            app(ErrorCapture::class)->errorPage($request, $status, $exception);
        } catch (Throwable) {
            // the answer is out already: nothing here may change how the request ends
        }
    }
}
