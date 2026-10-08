<?php

namespace App\Entitlements;

use Closure;

/**
 * What the admin's tools need to know about each feature, apart from how a workspace is answered: which switches are
 * locked (core features that every workspace relies on), which features reach out to customers (the "pause all
 * automation" control stops those), which are part of the essentials preset, and which features each one needs.
 *
 * By default every answer comes from the Feature enum, which is the code's declaration. It is a class, not static
 * calls, so that the machinery can be proven with features that are ordinary even while the only features that exist
 * are core ones.
 */
final class FeatureCatalogue
{
    /**
     * @param  ?Closure(Feature): bool  $locked
     * @param  ?Closure(Feature): bool  $touchesCustomers
     * @param  ?Closure(Feature): bool  $essential
     * @param  ?Closure(Feature): list<Feature>  $dependsOn
     */
    public function __construct(
        private readonly ?Closure $locked = null,
        private readonly ?Closure $touchesCustomers = null,
        private readonly ?Closure $essential = null,
        private readonly ?Closure $dependsOn = null,
    ) {}

    /** @return list<Feature> */
    public function all(): array
    {
        return Feature::cases();
    }

    /** A locked switch cannot be changed from the cockpit, the command line or a preset. */
    public function isLocked(Feature $feature): bool
    {
        return $this->locked !== null ? (bool) ($this->locked)($feature) : $feature->isCore();
    }

    /** @return list<Feature> */
    public function switchable(): array
    {
        return array_values(array_filter($this->all(), fn (Feature $f) => ! $this->isLocked($f)));
    }

    public function touchesCustomers(Feature $feature): bool
    {
        return $this->touchesCustomers !== null ? (bool) ($this->touchesCustomers)($feature) : $feature->touchesCustomers();
    }

    public function isEssential(Feature $feature): bool
    {
        return $this->essential !== null ? (bool) ($this->essential)($feature) : $feature->isEssential();
    }

    /** @return list<Feature> */
    public function dependsOn(Feature $feature): array
    {
        return $this->dependsOn !== null ? ($this->dependsOn)($feature) : $feature->dependsOn();
    }

    /**
     * The features that list this one among the things they need.
     *
     * @return list<Feature>
     */
    public function requiredBy(Feature $feature): array
    {
        return array_values(array_filter($this->all(), fn (Feature $other) => in_array($feature, $this->dependsOn($other), true)));
    }
}
