<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin console does not exist for anyone who is not platform staff with admin rights. */
final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), 404);

        return $next($request);
    }
}
