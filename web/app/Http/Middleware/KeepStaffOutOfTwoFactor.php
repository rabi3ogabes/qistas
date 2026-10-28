<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor does not exist for the admin team (owner's decision, 28 Oct 2026; see User::mayUseTwoFactor). Every route
 * that sets it up, confirms it, shows its recovery codes or manages trusted devices answers 404 to platform staff, so
 * nobody on the team can start something that sign-in would then ignore. The pages guests see (the code prompt) are not
 * touched: a guest is nobody yet, and staff are never sent there.
 */
final class KeepStaffOutOfTwoFactor
{
    private const ROUTES = ['two-factor.*', 'security.recovery-codes', 'security.two-factor.restart', 'security.trusted-devices.forget'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user !== null && ! $user->mayUseTwoFactor() && $request->routeIs(...self::ROUTES), 404);

        return $next($request);
    }
}
