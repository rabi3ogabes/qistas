<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Where the sign-in code tells the person's sign-in history what happened (App\Auth\SignInRecorder writes the row). A
 * record is a side note, so two rules hold here for every hook point, which calls this class and never the recorder:
 *
 *  - It is written after the answer is sent (a deferred callback), so it adds no time to the answer. That matters for a
 *    wrong password: only an existing account gets a record, and the work must not tell an unknown e-mail apart.
 *  - Whatever goes wrong while writing it is reported (its kind only, never the values the row was to hold) and
 *    swallowed, so a broken record can never stop a sign-in, a sign-up or the usual answer to a wrong password.
 *
 * The hook points (plan deviation D4): Fortify's login, registration and two-factor responses and the invitation
 * sign-up for the website, the password check (App\Actions\Auth\AuthenticateUser) for a wrong password, and the API's
 * AuthController for the phone app. Not Laravel's login event: it also fires when a remember-me cookie restores a
 * session, which is no sign-in.
 */
final class SignInLog
{
    /** A completed sign-in or sign-up. */
    public function success(User $user, Request $request, string $method = 'password', string $channel = 'web'): void
    {
        $this->afterTheAnswer(fn (SignInRecorder $recorder) => $recorder->success($user, $request, $method, $channel));
    }

    /** A wrong password for this existing account (the recorder writes at most one a minute per address). */
    public function failed(User $user, Request $request, string $method = 'password', string $channel = 'web'): void
    {
        $this->afterTheAnswer(fn (SignInRecorder $recorder) => $recorder->failed($user, $request, $method, $channel));
    }

    /**
     * Everything the recorder does (the throttle check included) runs once the answer is out. `always`: Laravel skips
     * deferred work after an answer of 400 or more, and the phone app's answer to a wrong password is a 401.
     *
     * @param  Closure(SignInRecorder): mixed  $write
     */
    private function afterTheAnswer(Closure $write): void
    {
        defer(fn () => $this->safely($write))->always();
    }

    /**
     * The recorder is made inside the guard too, so even one that cannot be built locks nobody out. It runs outside any
     * database transaction (on PostgreSQL a failed statement would spoil the caller's).
     *
     * @param  Closure(SignInRecorder): mixed  $write
     */
    private function safely(Closure $write): void
    {
        try {
            $write(app(SignInRecorder::class));
        } catch (Throwable $e) {
            report(self::withoutDetails($e));
        }
    }

    /**
     * What is reported when a record cannot be written: the kind of failure and where, never the original exception or
     * its message. A database error's message carries the row's values (the person's address and browser) and the
     * server's words about them; the SQL with its placeholders and the SQLSTATE say what failed, the same for everyone.
     */
    private static function withoutDetails(Throwable $e): RuntimeException
    {
        $what = $e::class.' at '.$e->getFile().':'.$e->getLine();

        if ($e instanceof QueryException) {
            $what .= ' (SQLSTATE '.($e->errorInfo[0] ?? $e->getCode()).', SQL: '.$e->getSql().')';
        }

        return new RuntimeException('A sign-in record could not be written: '.$what);
    }
}
