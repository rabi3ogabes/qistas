<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureUsage;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hitsOf(Tenant $tenant, Feature $feature, ?string $day = null): ?int
{
    $row = DB::table('feature_usage_daily')->where(['tenant_id' => $tenant->id, 'feature_key' => $feature->value])
        ->when($day, fn ($q) => $q->where('day', $day))->first();

    return $row === null ? null : (int) $row->hits;
}

describe('counting how much each feature is used', function () {
    it('adds one to the day\'s row for that workspace, however many times it is hit', function () {
        $this->freezeTime();
        $a = workspaceOn('pro');
        $b = workspaceOn('pro');

        FeatureUsage::hit(Feature::AdvancedReports, $a);
        FeatureUsage::hit(Feature::AdvancedReports, $a);
        FeatureUsage::hit(Feature::AdvancedReports, $b);

        expect(hitsOf($a, Feature::AdvancedReports))->toBe(2)
            ->and(hitsOf($b, Feature::AdvancedReports))->toBe(1)
            ->and(DB::table('feature_usage_daily')->count())->toBe(2);
    });

    it('starts a new row each day', function () {
        $this->freezeTime();
        $tenant = workspaceOn('pro');

        FeatureUsage::hit(Feature::AdvancedReports, $tenant);
        $this->travel(1)->days();
        FeatureUsage::hit(Feature::AdvancedReports, $tenant);

        expect(DB::table('feature_usage_daily')->where('tenant_id', $tenant->id)->count())->toBe(2);
    });

    it('uses the current workspace when none is given, and counts nothing without one', function () {
        $tenant = workspaceOn('pro');

        asTenant($tenant, fn () => FeatureUsage::hit(Feature::AdvancedReports));
        FeatureUsage::hit(Feature::AdvancedReports);

        expect(hitsOf($tenant, Feature::AdvancedReports))->toBe(1)->and(DB::table('feature_usage_daily')->count())->toBe(1);
    });

    it('does not fail when the row already exists, as when two requests arrive together', function () {
        $this->freezeTime();
        $tenant = workspaceOn('pro');
        DB::table('feature_usage_daily')->insert(['tenant_id' => $tenant->id, 'feature_key' => 'advanced_reports', 'day' => now()->toDateString(), 'hits' => 5]);

        FeatureUsage::hit(Feature::AdvancedReports, $tenant);

        expect(hitsOf($tenant, Feature::AdvancedReports))->toBe(6);
    });

    it('stores only counts: who, which feature, which day, how many', function () {
        expect(Schema::getColumnListing('feature_usage_daily'))->toEqualCanonicalizing(['tenant_id', 'feature_key', 'day', 'hits']);
    });
});

describe('how many workspaces used a feature lately', function () {
    it('counts each workspace once, for the last thirty days only', function () {
        $this->freezeTime();
        [$today, $recent, $old] = [workspaceOn('pro'), workspaceOn('pro'), workspaceOn('pro')];
        $row = fn (Tenant $t, string $day, string $key = 'advanced_reports') => DB::table('feature_usage_daily')
            ->insert(['tenant_id' => $t->id, 'feature_key' => $key, 'day' => $day, 'hits' => 1]);

        $row($today, now()->toDateString());
        $row($today, now()->subDay()->toDateString());          // the same workspace twice still counts once
        $row($recent, now()->subDays(29)->toDateString());
        $row($old, now()->subDays(31)->toDateString());          // too long ago
        $row($old, now()->toDateString(), 'export_csv');         // another feature

        expect(FeatureUsage::workspacesInLast30Days(Feature::AdvancedReports))->toBe(2)
            ->and(FeatureUsage::workspacesInLast30Days(Feature::ExportCsv))->toBe(1)
            ->and(FeatureUsage::workspacesInLast30Days(Feature::CustomBranding))->toBe(0);
    });
});
