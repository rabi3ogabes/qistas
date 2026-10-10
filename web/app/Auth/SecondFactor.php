<?php

namespace App\Auth;

use App\Http\ApiException;
use App\Models\User;
use App\Support\Audit;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * The second step for someone who turned two-step sign-in on: a code from their authenticator app, or one of their
 * recovery codes (used up when it works). Signing in and anything as serious (deleting an account) ask for it the
 * same way. A person without two-step sign-in passes straight through.
 */
final class SecondFactor
{
    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /** @throws ApiException two_factor_required (422) when nothing was given, invalid_two_factor_code (401) when it is wrong */
    public function verify(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (! $user->hasConfirmedTwoFactor()) {
            return;
        }

        $code = trim((string) $code);
        $recovery = trim((string) $recoveryCode);

        if ($code === '' && $recovery === '') {
            throw new ApiException('two_factor_required', __('Enter the code from your authenticator app.'), 422);
        }

        if ($code !== '' && $this->provider->verify(decrypt($user->two_factor_secret), $code)) {
            return;
        }

        if ($recovery !== '') {
            $match = collect($user->recoveryCodes())->first(fn (string $stored) => hash_equals($stored, $recovery));

            if ($match !== null) {
                $user->replaceRecoveryCode($match);

                return;
            }
        }

        Audit::record('login.two_factor_failed', userId: $user->id);

        throw new ApiException('invalid_two_factor_code', __('That code is not valid.'), 401);
    }
}
