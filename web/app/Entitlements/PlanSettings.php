<?php

namespace App\Entitlements;

use App\Models\Plan;

/**
 * What a plan gives for every feature: the admin's setting if there is one, otherwise the built-in default for
 * free and pro, otherwise nothing. The entitlement engine, the pricing page and the admin matrix all read
 * this one resolution, so what is advertised and what is enforced cannot drift apart.
 */
final class PlanSettings
{
    /** @return array<string, array{enabled: bool, limit: ?int}> keyed by feature key, in catalogue order */
    public static function resolve(Plan $plan): array
    {
        $rows = $plan->features()->get()->keyBy('feature_key');

        $settings = [];
        foreach (Feature::cases() as $feature) {
            $row = $rows->get($feature->value);

            $settings[$feature->value] = $row !== null
                ? ['enabled' => $row->enabled, 'limit' => $row->limit_value]
                : ($feature->defaultFor($plan->key) ?? ['enabled' => false, 'limit' => null]);
        }

        return $settings;
    }
}
