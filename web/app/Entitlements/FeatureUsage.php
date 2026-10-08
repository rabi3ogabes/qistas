<?php

namespace App\Entitlements;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Counts how often a workspace uses a feature, per day: how many times, never what was done. Call `hit()` from a
 * feature's successful primary action. The admin's cockpit reads it to show how many workspaces use a feature.
 */
final class FeatureUsage
{
    /** One more use today. Two requests arriving together both count: the row is made if needed, then incremented in SQL. */
    public static function hit(Feature $feature, ?Tenant $tenant = null): void
    {
        $tenantId = $tenant->id ?? app(CurrentTenant::class)->id();
        if ($tenantId === null) {
            return;
        }

        $row = ['tenant_id' => $tenantId, 'feature_key' => $feature->value, 'day' => now()->toDateString()];

        DB::table('feature_usage_daily')->insertOrIgnore([...$row, 'hits' => 0]);
        DB::table('feature_usage_daily')->where($row)->update(['hits' => DB::raw('hits + 1')]);
    }

    /**
     * The same count for every feature at once, in one query: how many workspaces used each in the last thirty days.
     *
     * @return array<string, int> by feature key; a feature nobody used is absent
     */
    public static function workspacesInLast30DaysByFeature(): array
    {
        return DB::table('feature_usage_daily')
            ->where('day', '>=', now()->subDays(30)->toDateString())
            ->groupBy('feature_key')
            ->selectRaw('feature_key, count(distinct tenant_id) as workspaces')
            ->pluck('workspaces', 'feature_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** How many different workspaces used the feature in the last thirty days (today included). */
    public static function workspacesInLast30Days(Feature $feature): int
    {
        return (int) DB::table('feature_usage_daily')
            ->where('feature_key', $feature->value)
            ->where('day', '>=', now()->subDays(30)->toDateString())
            ->distinct()
            ->count('tenant_id');
    }
}
