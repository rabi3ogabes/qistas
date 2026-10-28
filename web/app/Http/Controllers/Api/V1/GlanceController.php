<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureUsage;
use App\Glance\GlanceSnapshot;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the phone's home-screen widget reads (see GlanceSnapshot). It is deliberately not behind the `feature:` middleware,
 * which answers an error: a widget already installed has to be told the feature is off, so it can fall back to "Open
 * Qistas" instead of showing a failure. Off, for the platform or for the plan, is a plain `{enabled: false}` and nothing more.
 */
final class GlanceController
{
    public function __invoke(Request $request, CurrentTenant $current): JsonResponse
    {
        $tenant = $current->get() ?? abort(404);

        // Asked at every read: the platform's switch and the plan can change between two of them.
        if (! Entitlements::for($tenant)->check(Feature::HomeWidget)->enabled()) {
            return response()->json(['data' => ['enabled' => false]]);
        }

        $request->validate(['names' => ['sometimes', 'boolean']]);
        $snapshot = GlanceSnapshot::for($tenant, $request->boolean('names'));
        FeatureUsage::hit(Feature::HomeWidget, $tenant);

        return response()->json(['data' => $snapshot]);
    }
}
