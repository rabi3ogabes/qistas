<?php

namespace App\Entitlements;

use Illuminate\Support\Facades\DB;

/**
 * Reads the platform switches. A feature with no row yet is at its launch state, so a feature added in code can
 * never be "denied" or "allowed" by accident while its row is missing. What is stored is reported as it is, for core
 * features too: FeatureControl and the cockpit refuse to change a core switch, and only editing the table by hand can.
 */
final class PlatformFeatures
{
    public static function state(Feature $feature): PlatformState
    {
        $stored = DB::table('platform_features')->where('feature_key', $feature->value)->value('state');

        return PlatformState::tryFrom((string) $stored) ?? $feature->launchState();
    }

    /**
     * Every declared feature's state, in catalogue order, from one query.
     *
     * @return array<string, PlatformState>
     */
    public static function all(): array
    {
        $stored = DB::table('platform_features')->pluck('state', 'feature_key');

        $states = [];
        foreach (Feature::cases() as $feature) {
            $states[$feature->value] = PlatformState::tryFrom((string) ($stored[$feature->value] ?? '')) ?? $feature->launchState();
        }

        return $states;
    }

    /**
     * Adds a row, at its launch state, for every declared feature that has none. Never changes a row that exists, so
     * it is safe to run on every deployment and from several copies at once.
     *
     * @return int how many rows were added
     */
    public static function sync(): int
    {
        $existing = DB::table('platform_features')->pluck('feature_key')->all();
        $now = now();

        $missing = [];
        foreach (Feature::cases() as $feature) {
            if (! in_array($feature->value, $existing, true)) {
                $missing[] = ['feature_key' => $feature->value, 'state' => $feature->launchState()->value, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        return $missing === [] ? 0 : DB::table('platform_features')->insertOrIgnore($missing);
    }
}
