<?php

namespace App\Site;

use App\Entitlements\Feature;
use App\Entitlements\FeatureType;
use App\Entitlements\PlanSettings;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * The public pricing, read live from the plans the admin edits. Nothing is cached, so an edit in the admin
 * console appears on the website on the very next page view.
 */
final class PricingCatalog
{
    /** @return Collection<int, PlanOffer> the plans listed publicly, cheapest first (by sort order) */
    public function offers(): Collection
    {
        return Plan::query()
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $plan) => new PlanOffer($plan, PlanSettings::resolve($plan)))
            ->values();
    }

    /**
     * How many of a counted feature the free plan allows, for copy such as "free for your first 5 customers".
     * Null when there is no number to quote (switched off, unlimited, or not a counted feature).
     */
    public function freeAllowance(Feature $feature): ?int
    {
        if ($feature->type() === FeatureType::Toggle) {
            return null;
        }

        $setting = PlanSettings::resolve(Plan::default())[$feature->value];

        return $setting['enabled'] ? $setting['limit'] : null;
    }
}
