<?php

namespace App\Entitlements;

use App\Models\Plan;
use App\Models\PlatformFeature;
use App\Models\Tenant;
use App\Models\TenantOverride;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only writer of the platform's switches: used by the admin's screen, its command line and its presets, so the
 * rules are the same everywhere. Every change is audited with who, from, to and why. A switch never deletes anything:
 * it decides what is offered and what keeps running, and a record made while a feature was on stays.
 *
 * Rules: a core feature's switch is locked; reducing a feature that workspaces have used in the last 30 days needs a
 * reason (an undo is exempt, it is a reversal); a preset records how things were first, so it can be undone.
 */
final class FeatureControl
{
    public const PRESETS = ['dark_launch', 'essentials', 'full', 'restore_previous'];

    private const RANK = ['off' => 0, 'beta' => 1, 'on' => 2];

    /** A reason shorter than this is not a reason. */
    private const MIN_REASON = 3;

    public function __construct(private readonly FeatureCatalogue $catalogue) {}

    /**
     * Set a feature's platform state.
     *
     * @return list<Feature> the features that need this one and are switched on or in beta: they stop with it
     *
     * @throws FeatureControlException
     */
    public function setState(Feature $feature, PlatformState $to, ?string $reason, ?User $by, bool $undo = false): array
    {
        if ($this->catalogue->isLocked($feature)) {
            throw FeatureControlException::coreFeature($feature);
        }

        $from = PlatformFeatures::state($feature);
        if ($from === $to) {
            return [];
        }

        $reduces = self::RANK[$to->value] < self::RANK[$from->value];
        $reason = $this->reason($reason);

        if ($reduces && ! $undo && $reason === null && FeatureUsage::workspacesInLast30Days($feature) > 0) {
            throw FeatureControlException::reasonRequired();
        }

        $this->write($feature, $to, $reason, $by);
        Audit::record('feature.state_changed', null, [
            'feature' => $feature->value, 'from' => $from->value, 'to' => $to->value, 'reason' => $reason, 'undo' => $undo, 'actor' => $this->actor($by),
        ], userId: $by?->id);

        return $reduces ? $this->activeDependents($feature) : [];
    }

    /**
     * What one plan gives for one feature. Core features' plan settings are editable: that is today's plan matrix.
     *
     * @throws FeatureControlException when the limit is not allowed for this kind of feature
     */
    public function setPlan(Feature $feature, Plan $plan, bool $enabled, ?int $limit, ?User $by): void
    {
        $before = PlanSettings::resolve($plan)[$feature->value];

        try {
            $plan->setFeature($feature, $enabled, $limit);
        } catch (InvalidArgumentException) {
            throw FeatureControlException::invalidLimit();
        }

        Audit::record('feature.plan_changed', null, [
            'feature' => $feature->value, 'plan' => $plan->key, 'before' => $before, 'after' => PlanSettings::resolve($plan)[$feature->value], 'actor' => $this->actor($by),
        ], userId: $by?->id);
    }

    /**
     * Let one workspace use a feature while the platform has it in beta (it works at any time, so a pilot can be
     * prepared before the switch is flipped).
     *
     * @throws FeatureControlException when no reason is given
     */
    public function grantBeta(Feature $feature, Tenant $tenant, string $reason, ?CarbonInterface $until, ?User $by): TenantOverride
    {
        $reason = $this->reason($reason) ?? throw FeatureControlException::reasonRequired();

        $override = $tenant->overrides()->create([
            'feature_key' => $feature->value, 'enabled' => true, 'limit_value' => null, 'reason' => $reason, 'expires_at' => $until,
        ]);
        $override->created_by_user_id = $by?->id;
        $override->save();

        Audit::record('feature.beta_granted', null, [
            'feature' => $feature->value, 'workspace' => $tenant->id, 'reason' => $reason, 'expires_at' => $until?->toIso8601String(), 'actor' => $this->actor($by),
        ], userId: $by?->id);

        return $override;
    }

    public function revokeBeta(TenantOverride $override, ?User $by): void
    {
        Audit::record('feature.beta_revoked', null, [
            'feature' => $override->feature_key, 'workspace' => $override->tenant_id, 'actor' => $this->actor($by),
        ], userId: $by?->id);

        $override->delete();
    }

    /**
     * What a preset would change, and nothing more.
     *
     * @return list<array{feature: string, from: string, to: string}>
     *
     * @throws FeatureControlException
     */
    public function previewPreset(string $preset): array
    {
        $target = $this->target($preset);

        $diff = [];
        foreach ($this->catalogue->switchable() as $feature) {
            $to = $target[$feature->value] ?? null;
            $from = PlatformFeatures::state($feature);

            if ($to !== null && $to !== $from) {
                $diff[] = ['feature' => $feature->value, 'from' => $from->value, 'to' => $to->value];
            }
        }

        return $diff;
    }

    /**
     * Apply a preset to the switchable features, after recording how they were, so "restore_previous" can undo it.
     *
     * @throws FeatureControlException
     */
    public function applyPreset(string $preset, string $reason, ?User $by): void
    {
        $diff = $this->previewPreset($preset);
        $reason = $this->reason($reason) ?? throw FeatureControlException::reasonRequired();

        DB::transaction(function () use ($preset, $reason, $by, $diff): void {
            DB::table('feature_snapshots')->insert([
                'id' => (string) Str::uuid7(),
                'states' => json_encode($this->currentStates(), JSON_THROW_ON_ERROR),
                'preset' => $preset,
                'reason' => $reason,
                'taken_by_user_id' => $by?->id,
                'created_at' => now(),
            ]);

            foreach ($diff as $change) {
                $this->write(Feature::from($change['feature']), PlatformState::from($change['to']), $reason, $by);
            }

            Audit::record('feature.preset_applied', null, ['preset' => $preset, 'reason' => $reason, 'changes' => $diff, 'actor' => $this->actor($by)], userId: $by?->id);
        });
    }

    /**
     * The emergency control: every feature that reaches out to customers goes off at once.
     *
     * @return int how many were switched off
     *
     * @throws FeatureControlException when no reason is given
     */
    public function pauseAutomation(string $reason, ?User $by): int
    {
        $reason = $this->reason($reason) ?? throw FeatureControlException::reasonRequired();

        $paused = 0;
        foreach ($this->catalogue->switchable() as $feature) {
            if ($this->catalogue->touchesCustomers($feature) && PlatformFeatures::state($feature) !== PlatformState::Off) {
                $this->setState($feature, PlatformState::Off, $reason, $by);
                $paused++;
            }
        }

        Audit::record('feature.preset_applied', null, ['preset' => 'pause_automation', 'reason' => $reason, 'paused' => $paused, 'actor' => $this->actor($by)], userId: $by?->id);

        return $paused;
    }

    /** @return list<Feature> features that need $feature and are not already off */
    public function activeDependents(Feature $feature): array
    {
        return array_values(array_filter(
            $this->catalogue->requiredBy($feature),
            fn (Feature $dependent) => PlatformFeatures::state($dependent) !== PlatformState::Off,
        ));
    }

    /**
     * The state every switchable feature should have under a preset.
     *
     * @return array<string, PlatformState>
     *
     * @throws FeatureControlException
     */
    private function target(string $preset): array
    {
        $switchable = $this->catalogue->switchable();

        return match ($preset) {
            'dark_launch' => $this->all($switchable, PlatformState::Off),
            'full' => $this->all($switchable, PlatformState::On),
            'essentials' => collect($switchable)->mapWithKeys(fn (Feature $f) => [$f->value => $this->catalogue->isEssential($f) ? PlatformState::On : PlatformState::Off])->all(),
            'restore_previous' => $this->latestSnapshot() ?? throw FeatureControlException::noSnapshot(),
            default => throw FeatureControlException::unknownPreset(),
        };
    }

    /**
     * @param  list<Feature>  $features
     * @return array<string, PlatformState>
     */
    private function all(array $features, PlatformState $state): array
    {
        return collect($features)->mapWithKeys(fn (Feature $f) => [$f->value => $state])->all();
    }

    /** @return array<string, string> */
    private function currentStates(): array
    {
        $states = [];
        foreach ($this->catalogue->switchable() as $feature) {
            $states[$feature->value] = PlatformFeatures::state($feature)->value;
        }

        return $states;
    }

    /** @return ?array<string, PlatformState> */
    private function latestSnapshot(): ?array
    {
        $json = DB::table('feature_snapshots')->orderByDesc('created_at')->orderByDesc('id')->value('states');
        if (! is_string($json)) {
            return null;
        }

        $states = [];
        foreach ((array) json_decode($json, true) as $key => $value) {
            $state = PlatformState::tryFrom((string) $value);
            if ($state !== null && Feature::tryFrom((string) $key) !== null) {
                $states[(string) $key] = $state;
            }
        }

        return $states;
    }

    private function write(Feature $feature, PlatformState $state, ?string $reason, ?User $by): void
    {
        PlatformFeature::updateOrCreate(
            ['feature_key' => $feature->value],
            ['state' => $state, 'reason' => $reason, 'changed_by_user_id' => $by?->id, 'changed_at' => now()],
        );
    }

    /** The reason, trimmed, or null when there is none worth keeping. */
    private function reason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return mb_strlen($reason) >= self::MIN_REASON ? mb_substr($reason, 0, 500) : null;
    }

    private function actor(?User $by): string
    {
        return $by === null ? 'cli' : 'admin';
    }
}
