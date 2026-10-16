<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * One press, one save. A second request with the same one-time token from the same person (a double click, a second tap
 * while a slow page loads, a retry after a time-out) is not run again: it waits for the first to finish and gets the
 * first answer, so nothing is saved twice.
 *
 * - On the web, resources/js/forms.js gives every form a "_once" token when it is sent (web group, every change). A
 *   first press that came back with mistakes to fix is let go, so the same form can be sent again once they are fixed.
 * - In the app, an "add" (customers, contracts, products, tags...: routes given the `once` middleware) may carry an
 *   Idempotency-Key header; a repeat gets the first successful answer again, marked Idempotent-Replayed. An answer
 *   that was not a success lets the key go.
 *
 * Only signed-in people's requests are guarded (a sign-in or sign-up changes the session itself, so a repeat could not
 * be answered with the first's result; the form's own lock covers those).
 */
final class PreventDuplicateSubmissions
{
    public const FIELD = '_once';

    public const HEADER = 'Idempotency-Key';

    /** How long a press is remembered, and how long a repeat waits for the first to finish. */
    private const REMEMBER_SECONDS = 600;

    private const WAIT_SECONDS = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $api = $request->is('api/*');
        $token = $api ? $request->header(self::HEADER) : $request->input(self::FIELD);
        $user = $request->user();

        if ($request->isMethodSafe() || $user === null || ! is_string($token) || preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $token) !== 1) {
            return $next($request);
        }

        $key = 'once:'.($api ? 'api' : 'web').':'.hash('sha256', $user->getAuthIdentifier().'|'.$token);

        if (! Cache::add($key, ['to' => null], self::REMEMBER_SECONDS)) {
            $answer = $this->firstAnswer($key, $request, $api);
            if ($answer !== null) {
                return $answer;
            }
            // The first came back with mistakes and was let go: this one runs as a new press.
            Cache::add($key, ['to' => null], self::REMEMBER_SECONDS);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        $location = $response->headers->get('Location');
        if ($api && $response->isSuccessful()) {
            Cache::put($key, ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()], self::REMEMBER_SECONDS);
        } elseif (! $api && $response->isRedirection() && is_string($location) && ! $request->session()->has('errors')) {
            Cache::put($key, ['to' => $location], self::REMEMBER_SECONDS);
        } else {
            // Mistakes to fix, a refusal, a download, a page: nothing was saved that a repeat could duplicate.
            Cache::forget($key);
        }

        return $response;
    }

    /** Waits for the first press to finish, then answers as it did; null when it was let go. */
    private function firstAnswer(string $key, Request $request, bool $api): ?Response
    {
        $until = microtime(true) + self::WAIT_SECONDS;

        while (true) {
            $first = Cache::get($key);
            if ($first === null) {
                return null;
            }
            if ($api && is_array($first) && isset($first['status'])) {
                return response((string) $first['body'], (int) $first['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
            }
            if (! $api && is_array($first) && is_string($first['to'] ?? null)) {
                // The first press may have left a message for the next page (its "saved" note): read the session again
                // so this answer keeps it instead of writing the older copy back over it.
                $request->session()->start();

                return redirect()->to($first['to']);
            }
            if (microtime(true) >= $until) {
                abort_if($api, 409, __('This is still being saved. Please wait a moment.'));

                return redirect()->back();
            }
            usleep(200_000);
        }
    }
}
