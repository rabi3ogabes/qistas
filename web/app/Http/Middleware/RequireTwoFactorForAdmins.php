<?php

namespace App\Http\Middleware;

use App\Auth\TeamTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a super admin requires two-factor for the team (Admin → Team security), admins cannot use the console until
 * they have a confirmed second factor. Otherwise it is each person's choice and the console opens either way.
 */
final class RequireTwoFactorForAdmins
{
    public function __construct(private readonly TeamTwoFactor $team) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->team->required() && ! $request->user()?->hasConfirmedTwoFactor()) {
            abort_if($request->expectsJson(), 403, __('Two-factor authentication is required for administrators.'));

            return redirect()->route('security')
                ->with('warning', __('Turn on two-factor authentication to use the admin console.'));
        }

        return $next($request);
    }
}
