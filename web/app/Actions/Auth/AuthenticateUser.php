<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Checks sign-in credentials for Fortify. Wrong email and wrong password are indistinguishable (same
 * message, same work done). A suspension is only revealed to someone who proved the password.
 */
final class AuthenticateUser
{
    private static ?string $decoyHash = null;

    public function __invoke(Request $request): ?User
    {
        $user = User::where('email', mb_strtolower(trim((string) $request->input(Fortify::username()))))->first();

        // Always do exactly one hash comparison so timing does not reveal whether the email exists.
        $matches = Hash::check((string) $request->input('password'), $user->password ?? $this->decoyHash());

        if ($user === null || ! $matches) {
            Audit::record('login.failed', userId: $user?->id);

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
