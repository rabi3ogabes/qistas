<?php

namespace App\Actions\Auth;

use App\Auth\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

/**
 * The step of signing in that asks for the authenticator code. On a browser the person chose to trust (see
 * TrustedDevice) the code is not asked again; the password always is. Everything else is Fortify's own behaviour.
 */
final class SkipTwoFactorOnTrustedDevice extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if (! $user instanceof User || ! $user->hasConfirmedTwoFactor() || ! Fortify::confirmsTwoFactorAuthentication()) {
            return $next($request);
        }

        if (app(TrustedDevice::class)->trusts($request, $user)) {
            return $next($request);
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }
}
