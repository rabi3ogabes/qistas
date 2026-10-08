<?php

namespace App\Http\Middleware;

use App\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Ends the session of anyone who has been suspended since they signed in. */
final class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            // The API has no session to end: the token simply stops being usable while the account is suspended.
            if ($request->is('api/*')) {
                throw new ApiException('account_suspended', __('Your account has been suspended. Please contact support.'), 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('account.suspended');
        }

        return $next($request);
    }
}
