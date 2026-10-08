<?php

namespace App\Console\Commands;

use App\Admin\FeatureCards;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\FeatureControlException;
use App\Entitlements\PlatformFeatures;
use App\Entitlements\PlatformState;
use App\Models\Plan;
use Illuminate\Console\Command;

/**
 * The operator's way to do what the admin's Feature control screen does, with the same rules and the same audit trail
 * (recorded as "cli"): list the features, switch one, set what a plan gives, or add the missing switches.
 *
 *     php artisan qistas:features list
 *     php artisan qistas:features state export_csv beta --reason="Pilot with one shop"
 *     php artisan qistas:features plan export_csv pro on
 *     php artisan qistas:features sync
 */
final class FeaturesCommand extends Command
{
    protected $signature = 'qistas:features
        {action : list, state, plan or sync}
        {feature? : the feature key, for state and plan}
        {value? : off, beta or on for state; the plan key for plan}
        {extra? : on or off, for plan}
        {--reason= : why (needed to reduce a feature that workspaces have been using)}
        {--limit= : the plan\'s limit or monthly allowance, for plan}';

    protected $description = 'List the platform\'s feature switches, change one, set a plan\'s setting, or add the missing switches';

    public function handle(FeatureControl $control, FeatureCards $cards): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->list($cards),
                'sync' => $this->sync(),
                'state' => $this->state($control),
                'plan' => $this->plan($control),
                default => $this->refuse('Unknown action. Use list, state, plan or sync.'),
            };
        } catch (FeatureControlException $e) {
            return $this->refuse($e->getMessage());
        }
    }

    private function list(FeatureCards $cards): int
    {
        $rows = [];
        foreach ($cards->build()['groups'] as $group) {
            foreach ($group['features'] as $card) {
                $plans = implode(' ', array_map(fn (array $plan) => $plan['key'].':'.($plan['enabled'] ? 'on' : 'off'), $card['plans']));
                $rows[] = [$card['key'], $group['label'], $card['state'].($card['locked'] ? ' (core)' : ''), $card['usage_30d'], $plans];
            }
        }

        $this->table(['Feature', 'Group', 'State', 'Used by (30 d)', 'Plans'], $rows);

        return self::SUCCESS;
    }

    private function sync(): int
    {
        $added = PlatformFeatures::sync();
        $this->line(sprintf('%d platform switches added.', $added));

        return self::SUCCESS;
    }

    private function state(FeatureControl $control): int
    {
        $feature = Feature::tryFrom((string) $this->argument('feature'));
        $state = PlatformState::tryFrom((string) $this->argument('value'));
        if ($feature === null || $state === null) {
            return $this->refuse('Usage: qistas:features state <feature> <off|beta|on> [--reason=...]');
        }

        $dependents = $control->setState($feature, $state, $this->option('reason'), null);

        $this->line(sprintf('%s is now %s.', $feature->value, $state->value));
        foreach ($dependents as $dependent) {
            $this->warn(sprintf('%s needs it and stops with it.', $dependent->value));
        }

        return self::SUCCESS;
    }

    private function plan(FeatureControl $control): int
    {
        $feature = Feature::tryFrom((string) $this->argument('feature'));
        $plan = Plan::where('key', (string) $this->argument('value'))->first();
        $extra = $this->argument('extra');
        if ($feature === null || $plan === null || ! in_array($extra, ['on', 'off'], true)) {
            return $this->refuse('Usage: qistas:features plan <feature> <plan key> <on|off> [--limit=N]');
        }

        $limit = $this->option('limit');
        $control->setPlan($feature, $plan, $extra === 'on', $limit === null || $limit === '' ? null : (int) $limit, null);

        $this->line(sprintf('%s on %s: %s.', $feature->value, $plan->key, $extra));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
