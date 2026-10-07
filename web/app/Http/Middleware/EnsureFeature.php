<?php

namespace App\Http\Middleware;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route guard: `feature:export_csv`. Must run after `tenant`. Throws FeatureLocked (402 / upgrade sheet). */
final class EnsureFeature
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = $this->current->get();
        abort_if($tenant === null, 403);

        Entitlements::for($tenant)->assertEnabled(Feature::from($feature));

        return $next($request);
    }
}
