<?php

namespace App\Settings;

use App\Entitlements\Entitlements;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One workspace's choices, typed. Reading never needs the feature to be on (a stored value is just a value); writing
 * does, is validated by the setting's own rules, and is audited with what it was and what it became. Changing a
 * setting never rewrites a past record: features read it when they act.
 */
final class TenantSettings
{
    private function __construct(private readonly Tenant $tenant) {}

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function get(string $key): mixed
    {
        $definition = $this->definition($key);
        $row = $this->inWorkspace(fn () => TenantSetting::where('key', $key)->first());

        return $row === null ? $definition->default : $definition->cast($row->value);
    }

    /**
     * Every given setting's value with one query.
     *
     * @param  list<SettingDefinition>  $definitions
     * @return array<string, mixed> by key
     */
    public function values(array $definitions): array
    {
        $stored = $this->inWorkspace(fn () => TenantSetting::whereIn('key', array_map(fn (SettingDefinition $d) => $d->key, $definitions))->pluck('value', 'key'));

        $values = [];
        foreach ($definitions as $definition) {
            $values[$definition->key] = $stored->has($definition->key) ? $definition->cast($stored[$definition->key]) : $definition->default;
        }

        return $values;
    }

    /**
     * @throws FeatureUnavailable when the platform has the feature off
     * @throws FeatureLocked when the plan lacks it
     * @throws ValidationException when the value is not allowed
     */
    public function set(string $key, mixed $value, ?User $by = null): void
    {
        $definition = $this->definition($key);

        Entitlements::for($this->tenant)->assertEnabled($definition->feature);

        $validated = Validator::make(['value' => $value], ['value' => $definition->validationRules()])->validate();
        $after = $definition->cast($validated['value']);
        $before = $this->get($key);

        if ($before === $after) {
            return;
        }

        $this->inWorkspace(function () use ($key, $after, $before, $by): void {
            $row = TenantSetting::updateOrCreate(['key' => $key], ['value' => $after]);

            Audit::record('settings.changed', $row, ['key' => $key, 'before' => $before, 'after' => $after], $this->tenant->id, $by?->id);
        });
    }

    private function definition(string $key): SettingDefinition
    {
        return app(SettingsRegistry::class)->find($key) ?? throw new InvalidArgumentException("There is no setting called [{$key}].");
    }

    private function inWorkspace(callable $callback): mixed
    {
        return app(CurrentTenant::class)->use($this->tenant, fn () => $callback());
    }
}
