<?php

namespace App\Site;

use App\Entitlements\Feature;
use App\Models\Plan;
use App\Support\Money;

/** One plan as the public sees it: its price and, per feature, exactly what the admin has switched on. */
final readonly class PlanOffer
{
    /** @param  array<string, array{enabled: bool, limit: ?int}>  $settings */
    public function __construct(public Plan $plan, private array $settings) {}

    public function isFree(): bool
    {
        return $this->plan->isFree();
    }

    /** The monthly price as an exact decimal string, or null when the plan has no listed price. */
    public function monthlyPrice(): ?string
    {
        return $this->plan->price_monthly;
    }

    public function yearlyPrice(): ?string
    {
        return $this->plan->price_yearly;
    }

    /** How much cheaper a year is than twelve months, as a whole percent; null when it is not cheaper or not priced. */
    public function yearlySavingPercent(): ?int
    {
        $monthly = $this->plan->price_monthly;
        $yearly = $this->plan->price_yearly;

        if ($monthly === null || $yearly === null || ! Money::isPositive($monthly)) {
            return null;
        }

        $twelve = Money::mul($monthly, '12');
        if (Money::cmp($yearly, $twelve) >= 0) {
            return null;
        }

        return (int) Money::round(Money::div(Money::mul(Money::sub($twelve, $yearly), '100'), $twelve), 0);
    }

    /** @return array{feature: Feature, enabled: bool, limit: ?int, summary: string, short: string} */
    public function feature(Feature $feature): array
    {
        ['enabled' => $enabled, 'limit' => $limit] = $this->settings[$feature->value];

        return [
            'feature' => $feature,
            'enabled' => $enabled,
            'limit' => $feature->type()->value === 'toggle' ? null : $limit,
            'summary' => $feature->summary($enabled, $limit),
            'short' => $feature->shortValue($enabled, $limit),
        ];
    }

    /** @return list<array{feature: Feature, enabled: bool, limit: ?int, summary: string, short: string}> every feature, in catalogue order */
    public function features(): array
    {
        return array_map(fn (Feature $f) => $this->feature($f), Feature::cases());
    }
}
