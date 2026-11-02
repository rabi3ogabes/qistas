<?php

namespace App\Actions\Auth;

use App\Auth\SignInLog;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Checks sign-in credentials for Fortify (the website) and for the phone app's API. Wrong email and wrong password are
 * indistinguishable to the person signing in (same message, same hash work). A suspension is only revealed to someone
 * who proved the password.
 *
 * A wrong password for an account that exists also goes into that person's sign-in history (SignInLog::failed). This is
 * the one place every password check passes through, once per attempt that fails, on either channel. A success is
 * not recorded here: Fortify checks a good password twice per sign-in, and the sign-in may still need its code.
 */
final class AuthenticateUser
{
    private static ?string $decoyHash = null;

    public function __construct(private readonly SignInLog $signIns) {}

    /** @param  'web'|'app'  $channel  where the attempt comes from: Fortify calls this with the request alone (the website) */
    public function __invoke(Request $request, string $channel = 'web'): ?User
    {
        $user = User::where('email', mb_strtolower(trim((string) $request->input(Fortify::username()))))->first();

        // Always do exactly one hash comparison so timing does not reveal whether the email exists.
        $matches = Hash::check((string) $request->input('password'), $user->password ?? $this->decoyHash());

        if ($user === null || ! $matches) {
            Audit::record('login.failed', userId: $user?->id);

            // An unknown e-mail has no history to add to, and what was typed is never kept.
            if ($user !== null) {
                $this->signIns->failed($user, $request, channel: $channel);
            }

            return null;
        }

        if ($user->isSuspended()) {
            Audit::record('login.blocked', userId: $user->id);

            throw ValidationException::withMessages([
                Fortify::username() => __('Your account has been suspended. Please contact support.'),
            ]);
        }

        return $user;
    }

    private function decoyHash(): string
    {
        return self::$decoyHash ??= Hash::make(Str::random(40));
    }
}
