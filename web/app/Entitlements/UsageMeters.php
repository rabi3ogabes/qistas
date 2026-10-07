<?php

namespace App\Entitlements;

use App\Models\Tenant;
use Closure;
use LogicException;

/**
 * How to count a "limit" feature for a workspace (customers, active contracts, ...). Each module registers
 * its own meter, so this layer never depends on the modules. A counted feature without a meter is an error,
 * not a free pass.
 */
final class UsageMeters
{
    /** @var array<string, Closure(Tenant): int> */
    private array $meters = [];

    /** @param  Closure(Tenant): int  $meter */
    public function register(Feature $feature, Closure $meter): void
    {
        $this->meters[$feature->value] = $meter;
    }

    public function has(Feature $feature): bool
    {
        return isset($this->meters[$feature->value]);
    }

    public function count(Feature $feature, Tenant $tenant): int
    {
        $meter = $this->meters[$feature->value]
            ?? throw new LogicException("No usage meter is registered for [{$feature->value}].");

        return (int) $meter($tenant);
    }
}
