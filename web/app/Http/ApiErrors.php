<?php

namespace App\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Every failure on /api/ answers in one shape, whatever went wrong:
 *
 *     {"error": {"code": "validation_failed", "message": "...", "fields": {"email": ["..."]}}}
 *
 * (plan and limit failures, HTTP 402, are shaped by App\Entitlements\EntitlementException). The code is stable and
 * for programs; the message is translated and for people. Nothing about the server is revealed on an unexpected
 * failure. Not-found is the answer for another workspace's records, never "forbidden".
 */
final class ApiErrors
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => self::respond($e->errorCode, $e->getMessage(), $e->status, $e->extra),
            $e instanceof ValidationException => self::respond('validation_failed', __('Some of the information is not valid.'), 422, ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => self::respond('unauthenticated', __('Sign in to continue.'), 401),
            $e instanceof AccessDeniedHttpException => self::respond('forbidden', __('You are not allowed to do that.'), 403),
            $e instanceof NotFoundHttpException => self::respond('not_found', __('That does not exist.'), 404),
            $e instanceof MethodNotAllowedHttpException => self::respond('method_not_allowed', __('That method is not allowed here.'), 405, [], $e->getHeaders()),
            $e instanceof ThrottleRequestsException => self::respond('rate_limited', __('Too many requests. Please wait a moment and try again.'), 429, ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)], $e->getHeaders()),
            $e instanceof InvalidSignatureException => self::respond('invalid_signature', __('That link is not valid.'), 403),
            $e instanceof HttpExceptionInterface => self::respond('http_'.$e->getStatusCode(), $e->getMessage() !== '' ? $e->getMessage() : __('The request could not be completed.'), $e->getStatusCode(), [], $e->getHeaders()),
            // In debug mode the framework's own report is more useful; otherwise never reveal what broke.
            config('app.debug') => null,
            default => self::respond('server_error', __('Something went wrong on our side. Please try again.'), 500),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    private static function respond(string $code, string $message, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status, $headers);
    }
}
