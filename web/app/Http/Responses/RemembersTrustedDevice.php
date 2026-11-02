<?php

namespace App\Http\Responses;

use App\Auth\SignInLog;
use App\Auth\TrustedDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

/**
 * Fortify's answers to a correct authenticator code, at sign-in and when setup is confirmed, plus two things: when the
 * person ticked "Don't ask again on this device", the browser is remembered for TrustedDevice::DAYS days; and at
 * sign-in (only there: confirming setup is no sign-in) the sign-in record is written, now that the sign-in is complete.
 */
final class RemembersTrustedDevice implements TwoFactorConfirmedResponse, TwoFactorLoginResponse
{
    public function __construct(private readonly SignInLog $signIns) {}

    public function toResponse($request)
    {
        $user = $request->user();
        if ($user instanceof User && $request->boolean('remember_device')) {
            app(TrustedDevice::class)->trust($user);
        }

        if ($request->routeIs('two-factor.login.store')) {
            if ($user instanceof User) {
                $this->signIns->success($user, $request);
            }

            return $request->wantsJson() ? new JsonResponse('', 204) : redirect()->intended(Fortify::redirects('login'));
        }

        return $request->wantsJson() ? new JsonResponse('', 200) : back()->with('status', Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED);
    }
}
