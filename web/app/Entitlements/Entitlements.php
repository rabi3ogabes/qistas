<?php

namespace App\Entitlements;

use App\Models\Plan;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Answers "what may this workspace do?" for every feature, from data the admin controls.
 *
 * Resolution, first match wins (see resolveStatus):
 *   1. the platform switch is off -> `platform_off`;
 *   2. the platform switch is beta and this workspace has no live, enabled override -> `platform_off`;
 *   3. something the feature needs is unavailable -> `platform_off` or `plan_locked`, naming it;
 *   4. an active override for the workspace -> the value on its plan (see PlanSettings: a plan_features row, or the
 *      code default for the built-in plans) -> denied: `on` or `plan_locked`.
 * Nothing is cached, so an admin edit or a billing change is seen by the very next call.
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

    /**
     * @throws FeatureUnavailable when the platform has the feature off for this workspace
     * @throws FeatureLocked when the plan does not include it
     */
    public function assertEnabled(Feature $feature): void
    {
        $entitlement = $this->check($feature);

        if (! $entitlement->enabled()) {
            throw $this->refusal($entitlement);
        }
    }

    /**
     * Ensure $amount more of a counted feature may be added. Existing records are never touched: a workspace
     * above its limit after a downgrade keeps everything and simply cannot add more.
     *
     * @throws FeatureUnavailable|FeatureLocked|LimitReached
     */
    public function assertCanCreate(Feature $feature, int $amount = 1): void
    {
        $entitlement = $this->check($feature);

        if (! $entitlement->enabled()) {
            throw $this->refusal($entitlement);
        }
        if (! $entitlement->allows($amount)) {
            throw new LimitReached($feature, $entitlement->limit(), $entitlement->used());
        }
    }

    /**
     * Use $amount of this month's allowance of a quota feature. The check and the increment are one atomic
     * UPDATE, so two simultaneous requests cannot both take the last unit.
     *
     * @throws FeatureUnavailable|FeatureLocked|LimitReached
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
            throw $this->refusal($entitlement);
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

    /** The right kind of "no": nothing to buy when the platform has it off, an upgrade when only the plan lacks it. */
    private function refusal(Entitlement $entitlement): FeatureUnavailable|FeatureLocked
    {
        return $entitlement->status() === FeatureStatus::PlatformOff
            ? new FeatureUnavailable($entitlement->feature)
            : new FeatureLocked($entitlement->feature);
    }

    /**
     * @return array{plan: Plan, platform: array<string, PlatformState>, rows: array<string, array{enabled: bool, limit: ?int}>, overrides: array<string, array{enabled: bool, limit: ?int}>}
     */
    private function load(): array
    {
        $plan = $this->tenant->currentPlan();

        // Oldest first, so when several overrides are active for a feature the newest one wins.
        $overrides = [];
        foreach ($this->tenant->overrides()->active()->orderBy('created_at')->orderBy('id')->get() as $override) {
            $overrides[$override->feature_key] = ['enabled' => $override->enabled, 'limit' => $override->limit_value];
        }

        return ['plan' => $plan, 'platform' => PlatformFeatures::all(), 'rows' => PlanSettings::resolve($plan), 'overrides' => $overrides];
    }

    /**
     * The one rule that decides what a workspace gets for a feature, from plain data so it can be proven without a
     * database: the platform's switch per feature, each plan row, the workspace's live overrides (newest wins), and
     * optionally the dependency graph (by default the one the code declares).
     *
     * @param  array<string, PlatformState>  $platform
     * @param  array<string, array{enabled: bool, limit: ?int}>  $rows
     * @param  array<string, array{enabled: bool, limit: ?int}>  $overrides
     * @param  ?Closure(Feature): list<Feature>  $dependsOn
     * @param  list<string>  $path  features being resolved above this one, so a mistaken cycle ends instead of looping
     * @return array{status: FeatureStatus, detail: ?string}
     */
    public static function resolveStatus(Feature $feature, array $platform, array $rows, array $overrides, ?Closure $dependsOn = null, array $path = []): array
    {
        $dependsOn ??= fn (Feature $f): array => $f->dependsOn();
        $override = $overrides[$feature->value] ?? null;
        $state = $platform[$feature->value] ?? $feature->launchState();

        // The platform decides before any plan: off is off, and beta lets in only a workspace with a live grant.
        if ($state === PlatformState::Off || ($state === PlatformState::Beta && ! ($override['enabled'] ?? false))) {
            return ['status' => FeatureStatus::PlatformOff, 'detail' => null];
        }

        foreach ($dependsOn($feature) as $dependency) {
            if (in_array($dependency->value, [...$path, $feature->value], true)) {
                continue;
            }

            $needed = self::resolveStatus($dependency, $platform, $rows, $overrides, $dependsOn, [...$path, $feature->value]);
            if ($needed['status'] !== FeatureStatus::On) {
                return ['status' => $needed['status'], 'detail' => 'dependency:'.$dependency->value];
            }
        }

        $value = $override ?? $rows[$feature->value] ?? ['enabled' => false, 'limit' => null];

        return ['status' => $value['enabled'] ? FeatureStatus::On : FeatureStatus::PlanLocked, 'detail' => null];
    }

    /** @param  array{plan: Plan, platform: array<string, PlatformState>, rows: array<string, array{enabled: bool, limit: ?int}>, overrides: array<string, array{enabled: bool, limit: ?int}>}  $loaded */
    private function entitlement(Feature $feature, array $loaded): Entitlement
    {
        ['status' => $status, 'detail' => $detail] = self::resolveStatus($feature, $loaded['platform'], $loaded['rows'], $loaded['overrides']);

        $value = $loaded['overrides'][$feature->value] ?? $loaded['rows'][$feature->value];

        $type = $feature->type();
        // Only a feature that is on has an allowance, and an on/off feature has no count.
        $limit = $status === FeatureStatus::On && $type !== FeatureType::Toggle ? $value['limit'] : null;

        $used = match ($type) {
            FeatureType::Toggle => null,
            FeatureType::Limit => $this->meters->count($feature, $this->tenant),
            FeatureType::Quota => $this->quotaUsed($feature),
        };

        return new Entitlement($feature, $status, $detail, $limit, $used);
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
