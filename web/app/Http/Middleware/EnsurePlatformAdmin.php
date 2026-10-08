<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin console does not exist for anyone who is not platform staff with admin rights. Someone who is not
 * signed in is asked to sign in first (so an admin who follows a link gets there after signing in).
 */
final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort_if($request->expectsJson(), 401);

            return redirect()->guest(route('login'));
        }

        abort_unless($user->isPlatformAdmin(), 404);

        return $next($request);
    }
}
