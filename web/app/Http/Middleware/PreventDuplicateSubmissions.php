<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * One press, one save. resources/js/forms.js gives every web form a one-time "_once" token when it is sent; a second
 * request with the same token from the same person (a double click, a second tap while a slow page loads, a browser
 * retrying) is not run again: it waits for the first to finish and gets the first answer, so nothing is saved twice.
 *
 * Only signed-in people's changes are guarded (a sign-in or sign-up changes the session itself, so a repeat could not
 * be answered with the first's result; the form's own lock covers those). A first press that came back with mistakes
 * to fix is let go, so the same form can be sent again once they are fixed.
 */
final class PreventDuplicateSubmissions
{
    public const FIELD = '_once';

    /** How long a press is remembered, and how long a repeat waits for the first to finish. */
    private const REMEMBER_SECONDS = 600;

    private const WAIT_SECONDS = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->input(self::FIELD);
        $user = $request->user();

        if ($request->isMethodSafe() || $user === null || ! is_string($token) || preg_match('/^[A-Za-z0-9-]{16,64}$/', $token) !== 1) {
            return $next($request);
        }

        $key = 'once:'.hash('sha256', $user->getAuthIdentifier().'|'.$token);

        if (! Cache::add($key, ['to' => null], self::REMEMBER_SECONDS)) {
            $answer = $this->firstAnswer($key, $request);
            if ($answer !== null) {
                return $answer;
            }
            // The first press came back with mistakes and was let go: this one runs as a new press.
            Cache::add($key, ['to' => null], self::REMEMBER_SECONDS);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        $location = $response->headers->get('Location');
        if ($response->isRedirection() && is_string($location) && ! $request->session()->has('errors')) {
            Cache::put($key, ['to' => $location], self::REMEMBER_SECONDS);
        } else {
            // Mistakes to fix, a download, a page: nothing was saved that a repeat could duplicate.
            Cache::forget($key);
        }

        return $response;
    }

    /** Waits for the first press to finish, then answers as it did; null when it was let go. */
    private function firstAnswer(string $key, Request $request): ?Response
    {
        $until = microtime(true) + self::WAIT_SECONDS;

        while (true) {
            $first = Cache::get($key);
            if ($first === null) {
                return null;
            }
            if (is_array($first) && is_string($first['to'] ?? null)) {
                // The first press may have left a message for the next page (its "saved" note): read the session again
                // so this answer keeps it instead of writing the older copy back over it.
                $request->session()->start();

                return redirect()->to($first['to']);
            }
            if (microtime(true) >= $until) {
                return redirect()->back();
            }
            usleep(200_000);
        }
    }
}
