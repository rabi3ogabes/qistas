<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace from the signed-in user's memberships, never from anything the client sends.
 * It runs before route-model binding (see bootstrap/app.php) so other workspaces' ids resolve to 404.
 */
final class SetCurrentTenant
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $tenant = $user->primaryTenant();

            // Platform staff often have no workspace of their own: their front door is the admin home, where
            // the test workspace is one button away. A page, not an error.
            if ($tenant === null && $user->isPlatformAdmin() && ! $request->expectsJson()) {
                return redirect()->route('admin.home');
            }

            abort_if($tenant === null, 403, 'This account does not belong to a workspace.');
            $this->current->set($tenant);
        }

        return $next($request);
    }
}
