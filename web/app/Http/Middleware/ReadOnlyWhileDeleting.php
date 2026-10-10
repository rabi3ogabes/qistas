<?php

namespace App\Http\Middleware;

use App\Http\ApiException;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business its owner asked to delete is read-only until it is erased or restored: everyone may still look (and
 * export), nobody may change anything. The deletion's own routes (restore, delete a login) and signing out stay open.
 * The API answers 423 `workspace_deleting` with the last day to restore; the web app goes back with the same message.
 */
final class ReadOnlyWhileDeleting
{
    /** Routes that must keep working during the 30 days. */
    private const OPEN = ['api.account.deletion.*', 'app.account.delete.*', 'api.auth.logout', 'api.auth.logout-all'];

    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->current->get();

        if ($tenant === null || ! $tenant->isBeingDeleted() || $request->isMethodSafe() || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        $message = __('This business is being deleted, so nothing can be changed. The owner can restore it until :date.', ['date' => $tenant->restoreUntil()]);

        if ($request->is('api/*')) {
            throw new ApiException('workspace_deleting', $message, 423, ['restore_until' => $tenant->restoreUntil()]);
        }

        return back()->with('error', $message);
    }
}
