<?php

namespace App\Entitlements;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Answers "what may this workspace do?" for every feature, from data the admin controls.
 *
 * Resolution, first match wins: an active override for the workspace -> the value on its plan (a plan_features
 * row, or the code default for the built-in plans) -> denied. Nothing is cached, so an admin edit or a billing
 * change is seen by the very next call.
 */
final class Entitlements
{
    private function __construct(
        private readonly Tenant $tenant,
        private readonly UsageMeters $meters,
    ) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant, app(UsageMeters::class));
    }

    public function check(Feature $feature): Entitlement
    {
        return $this->entitlement($feature, $this->load());
    }

    /**
     * Everything at once, in the shape the API and the app use.
     *
     * @return array{plan: array{key: string, name: string}, features: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $loaded = $this->load();

        $features = [];
        foreach (Feature::cases() as $feature) {
            $features[$feature->value] = $this->entitlement($feature, $loaded)->toArray();
        }

        return ['plan' => ['key' => $loaded['plan']->key, 'name' => $loaded['plan']->name], 'features' => $features];
    }

    /** @throws FeatureLocked when the plan does not include the feature */
    public function assertEnabled(Feature $feature): void
    {
        if (! $this->check($feature)->enabled()) {
            throw new FeatureLocked($feature);
        }
    }

    /**
     * Ensure $amount more of a counted feature may be added. Existing records are never touched: a workspace
     * above its limit after a downgrade keeps everything and simply cannot add more.
     *
     * @throws FeatureLocked|LimitReached
     */
    public function assertCanCreate(Feature $feature, int $amount = 1): void
    {
        $entitlement = $this->check($feature);

        if (! $entitlement->enabled()) {
            throw new FeatureLocked($feature);
        }
        if (! $entitlement->allows($amount)) {
            throw new LimitReached($feature, $entitlement->limit(), $entitlement->used());
        }
    }

    /**
     * Use $amount of this month's allowance of a quota feature. The check and the increment are one atomic
     * UPDATE, so two simultaneous requests cannot both take the last unit.
     *
     * @throws FeatureLocked|LimitReached
     */
    public function consume(Feature $feature, int $amount = 1): void
    {
        if ($feature->type() !== FeatureType::Quota) {
            throw new LogicException("[{$feature->value}] is not a monthly quota and cannot be consumed.");
        }
        if ($amount < 1) {
            throw new InvalidArgumentException('The amount to consume must be at least 1.');
        }

        $entitlement = $this->check($feature);
        if (! $entitlement->enabled()) {
            throw new FeatureLocked($feature);
        }

        $scope = ['tenant_id' => $this->tenant->id, 'feature_key' => $feature->value, 'period' => $this->period()];

        DB::transaction(function () use ($scope, $amount, $entitlement, $feature): void {
            $now = now();
            DB::table('usage_counters')->insertOrIgnore([
                'id' => (string) Str::uuid7(), ...$scope, 'used' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $update = DB::table('usage_counters')->where($scope);
            if ($entitlement->limit() !== null) {
                $update->whereRaw('used + ? <= ?', [$amount, $entitlement->limit()]);
            }

            if ($update->update(['used' => DB::raw('used + '.$amount), 'updated_at' => $now]) === 0) {
                throw new LimitReached($feature, $entitlement->limit(), $this->quotaUsed($feature));
            }
        });
    }

    /**
     * @return array{plan: Plan, rows: array<string, array{enabled: bool, limit: ?int}>, overrides: array<string, array{enabled: bool, limit: ?int}>}
     */
    private function load(): array
    {
        $plan = $this->tenant->currentPlan();

        $rows = [];
        foreach ($plan->features()->get() as $row) {
            $rows[$row->feature_key] = ['enabled' => $row->enabled, 'limit' => $row->limit_value];
        }

        // Oldest first, so when several overrides are active for a feature the newest one wins.
        $overrides = [];
        foreach ($this->tenant->overrides()->active()->orderBy('created_at')->orderBy('id')->get() as $override) {
            $overrides[$override->feature_key] = ['enabled' => $override->enabled, 'limit' => $override->limit_value];
        }

        return ['plan' => $plan, 'rows' => $rows, 'overrides' => $overrides];
    }

    /** @param  array{plan: Plan, rows: array<string, array{enabled: bool, limit: ?int}>, overrides: array<string, array{enabled: bool, limit: ?int}>}  $loaded */
    private function entitlement(Feature $feature, array $loaded): Entitlement
    {
        $value = $loaded['overrides'][$feature->value]
            ?? $loaded['rows'][$feature->value]
            ?? $feature->defaultFor($loaded['plan']->key)
            ?? ['enabled' => false, 'limit' => null];

        $type = $feature->type();
        $enabled = $value['enabled'];
        // A switched-off feature has no allowance, and an on/off feature has no count.
        $limit = $enabled && $type !== FeatureType::Toggle ? $value['limit'] : null;

        $used = match ($type) {
            FeatureType::Toggle => null,
            FeatureType::Limit => $this->meters->count($feature, $this->tenant),
            FeatureType::Quota => $this->quotaUsed($feature),
        };

        return new Entitlement($feature, $enabled, $limit, $used);
    }

    private function quotaUsed(Feature $feature): int
    {
        return (int) DB::table('usage_counters')
            ->where(['tenant_id' => $this->tenant->id, 'feature_key' => $feature->value, 'period' => $this->period()])
            ->value('used');
    }

    /** Quotas reset on the first day of each calendar month (application timezone, UTC by default). */
    private function period(): string
    {
        return now()->format('Y-m');
    }
}
