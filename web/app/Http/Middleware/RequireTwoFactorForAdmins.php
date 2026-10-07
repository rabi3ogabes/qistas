<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admins cannot use the console until they have a confirmed second factor. */
final class RequireTwoFactorForAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasConfirmedTwoFactor()) {
            abort_if($request->expectsJson(), 403, __('Two-factor authentication is required for administrators.'));

            return redirect()->route('security')
                ->with('warning', __('Turn on two-factor authentication to use the admin console.'));
        }

        return $next($request);
    }
}
