<?php

namespace App\Admin;

use App\Entitlements\Feature;
use App\Entitlements\FeatureCatalogue;
use App\Entitlements\FeatureGroup;
use App\Entitlements\FeatureType;
use App\Entitlements\FeatureUsage;
use App\Entitlements\PlanSettings;
use App\Entitlements\PlatformFeatures;
use App\Entitlements\PlatformState;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\TenantOverride;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * @phpstan-type CardData array{
 *     key: string, label: string, description: string, off_behaviour: string, type: string, unit: string, state: string,
 *     locked: bool, scope: string,
 *     plans: list<array{key: string, name: string, enabled: bool, limit: int|null}>,
 *     usage_30d: int, depends_on: list<string>, required_by: list<string>, dependency_problem: bool,
 *     beta: list<array<string, mixed>>, history: list<array<string, mixed>>
 * }
 * @phpstan-type GroupData array{key: string, label: string, letter: string|null, features: list<CardData>}
 *
 * Everything the admin's cockpit shows about every feature, from a handful of queries (never one per feature):
 * its switch, the plans that include it, how many workspaces used it lately, what it needs and what needs it,
 * the workspaces let in early, and the last changes to its switch. The same shape is the page's data and the JSON
 * the screen's own script reads.
 */
final class FeatureCards
{
    private const HISTORY = 5;

    public function __construct(private readonly FeatureCatalogue $catalogue) {}

    /**
     * @return array{summary: array{on: int, beta: int, off: int}, groups: list<GroupData>}
     */
    public function build(): array
    {
        $states = PlatformFeatures::all();
        $plans = Plan::query()->orderBy('sort_order')->get();
        $settings = $plans->mapWithKeys(fn (Plan $plan) => [$plan->key => PlanSettings::resolve($plan)]);
        $usage = FeatureUsage::workspacesInLast30DaysByFeature();
        $beta = $this->beta();
        $history = $this->history();

        $summary = ['on' => 0, 'beta' => 0, 'off' => 0];
        $groups = [];

        foreach ($this->catalogue->all() as $feature) {
            $state = $states[$feature->value];
            $summary[$state->value]++;

            $group = $feature->group();
            $groups[$group->value] ??= ['key' => $group->value, 'label' => $group->label(), 'letter' => $group->letter(), 'features' => []];
            $groups[$group->value]['features'][] = $this->card($feature, $state, $states, $plans, $settings, $usage, $beta, $history);
        }

        // In the order the catalogue declares the groups.
        $ordered = [];
        foreach (FeatureGroup::cases() as $group) {
            if (isset($groups[$group->value])) {
                $ordered[] = $groups[$group->value];
            }
        }

        return ['summary' => $summary, 'groups' => $ordered];
    }

    /**
     * One feature's card.
     *
     * @return CardData|null
     */
    public function find(Feature $feature): ?array
    {
        foreach ($this->build()['groups'] as $group) {
            foreach ($group['features'] as $card) {
                if ($card['key'] === $feature->value) {
                    return $card;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, PlatformState>  $states
     * @param  Collection<int, Plan>  $plans
     * @param  Collection<string, array<string, array{enabled: bool, limit: ?int}>>  $settings
     * @param  array<string, int>  $usage
     * @param  array<string, list<array<string, mixed>>>  $beta
     * @param  array<string, list<array<string, mixed>>>  $history
     * @return CardData
     */
    private function card(Feature $feature, PlatformState $state, array $states, Collection $plans, Collection $settings, array $usage, array $beta, array $history): array
    {
        $needs = $this->catalogue->dependsOn($feature);

        return [
            'key' => $feature->value,
            'label' => $feature->label(),
            'description' => $feature->description(),
            'off_behaviour' => $feature->offBehaviour(),
            'type' => $feature->type()->value,
            'unit' => $feature->type() === FeatureType::Toggle ? '' : $feature->unit(),
            'state' => $state->value,
            'locked' => $this->catalogue->isLocked($feature),
            'scope' => $feature->scope(),
            'plans' => $plans->map(fn (Plan $plan) => ['key' => $plan->key, 'name' => $plan->name, ...$settings[$plan->key][$feature->value]])->values()->all(),
            'usage_30d' => $usage[$feature->value] ?? 0,
            'depends_on' => array_map(fn (Feature $f) => $f->value, $needs),
            'required_by' => array_map(fn (Feature $f) => $f->value, $this->catalogue->requiredBy($feature)),
            'dependency_problem' => array_any($needs, fn (Feature $dependency) => ($states[$dependency->value] ?? PlatformState::Off) !== PlatformState::On),
            'beta' => $beta[$feature->value] ?? [],
            'history' => $history[$feature->value] ?? [],
        ];
    }

    /**
     * The workspaces let in early and still in: grants that have not expired.
     *
     * @return array<string, list<array<string, mixed>>> by feature key
     */
    private function beta(): array
    {
        $overrides = TenantOverride::query()->active()->where('enabled', true)->with('tenant:id,name')->orderBy('created_at')->get();
        $names = User::query()->whereIn('id', $overrides->pluck('created_by_user_id')->filter())->pluck('name', 'id');

        $byFeature = [];
        foreach ($overrides as $override) {
            $byFeature[$override->feature_key][] = [
                'id' => $override->id,
                'workspace' => ['id' => $override->tenant_id, 'name' => $override->tenant?->name],
                'reason' => $override->reason,
                'expires_at' => $override->expires_at?->toIso8601String(),
                'granted_by' => $override->created_by_user_id === null ? null : ($names[$override->created_by_user_id] ?? null),
            ];
        }

        return $byFeature;
    }

    /**
     * The latest changes of each switch, newest first.
     *
     * @return array<string, list<array<string, mixed>>> by feature key
     */
    private function history(): array
    {
        $rows = AuditLog::query()->where('action', 'feature.state_changed')->orderByDesc('created_at')->orderByDesc('id')->limit(400)->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('name', 'id');

        $byFeature = [];
        foreach ($rows as $row) {
            $key = $row->changes['feature'] ?? null;
            if (! is_string($key) || count($byFeature[$key] ?? []) >= self::HISTORY) {
                continue;
            }

            $byFeature[$key][] = [
                'at' => $row->created_at?->toIso8601String(),
                'by' => $row->user_id === null ? null : ($names[$row->user_id] ?? null),
                'from' => $row->changes['from'] ?? null,
                'to' => $row->changes['to'] ?? null,
                'reason' => $row->changes['reason'] ?? null,
                'undo' => (bool) ($row->changes['undo'] ?? false),
            ];
        }

        return $byFeature;
    }
}
