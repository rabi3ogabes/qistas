<?php

namespace App\Settings;

use App\Entitlements\Entitlements;
use App\Models\Tenant;

/**
 * Every setting any feature declares. Features register theirs when the application boots; a workspace is only shown
 * the settings of features that are on for it (not plan-locked, not switched off by the platform).
 * One instance per application (see AppServiceProvider).
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function register(SettingDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function find(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * The settings this workspace may see and change right now, in the order they were registered.
     *
     * @return list<SettingDefinition>
     */
    public function definitionsFor(Tenant $tenant): array
    {
        if ($this->definitions === []) {
            return [];
        }

        $entitlements = Entitlements::for($tenant);
        $on = [];

        $visible = [];
        foreach ($this->definitions as $definition) {
            $on[$definition->feature->value] ??= $entitlements->check($definition->feature)->enabled();

            if ($on[$definition->feature->value]) {
                $visible[] = $definition;
            }
        }

        return $visible;
    }
}
