<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where the sign-in code tells the person's sign-in history what happened (App\Auth\SignInRecorder writes the row). A
 * record is a side note: whatever goes wrong while writing it is reported and swallowed here, so a broken record can
 * never stop a sign-in, a sign-up or the usual answer to a wrong password. Every hook point calls this class, never the
 * recorder itself.
 *
 * The hook points (plan deviation D4): Fortify's login, registration and two-factor responses for the website, the
 * password check (App\Actions\Auth\AuthenticateUser) for a wrong password, and the API's AuthController for the phone
 * app. Not Laravel's login event: it also fires when a remember-me cookie restores a session, which is no sign-in.
 */
final class SignInLog
{
    /** A completed sign-in or sign-up. */
    public function success(User $user, Request $request, string $method = 'password', string $channel = 'web'): void
    {
        $this->safely(fn (SignInRecorder $recorder) => $recorder->success($user, $request, $method, $channel));
    }

    /** A wrong password for this existing account (the recorder writes at most one a minute per address). */
    public function failed(User $user, Request $request, string $method = 'password', string $channel = 'web'): void
    {
        $this->safely(fn (SignInRecorder $recorder) => $recorder->failed($user, $request, $method, $channel));
    }

    /**
     * The recorder is made inside the guard too, so even one that cannot be built locks nobody out. Called outside any
     * database transaction (on PostgreSQL a failed statement would spoil the caller's).
     *
     * @param  Closure(SignInRecorder): mixed  $write
     */
    private function safely(Closure $write): void
    {
        try {
            $write(app(SignInRecorder::class));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
