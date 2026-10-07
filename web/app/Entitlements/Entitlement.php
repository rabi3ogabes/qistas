<?php

namespace App\Entitlements;

/** What one workspace may do with one feature right now. Immutable. */
final readonly class Entitlement
{
    /**
     * @param  ?int  $limit  null = unlimited (for counted features) or not applicable (on/off features)
     * @param  ?int  $used  null for on/off features
     */
    public function __construct(
        public Feature $feature,
        private bool $enabled,
        private ?int $limit,
        private ?int $used,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function used(): ?int
    {
        return $this->used;
    }

    public function unlimited(): bool
    {
        return $this->enabled && $this->feature->type() !== FeatureType::Toggle && $this->limit === null;
    }

    /** How many more may be added; null when there is no cap to count down from. Never negative. */
    public function remaining(): ?int
    {
        if ($this->feature->type() === FeatureType::Toggle) {
            return null;
        }
        if (! $this->enabled) {
            return 0;
        }

        return $this->limit === null ? null : max(0, $this->limit - (int) $this->used);
    }

    public function allows(int $amount = 1): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->limit === null || (int) $this->used + $amount <= $this->limit;
    }

    /** @return array{type: string, enabled: bool, limit: ?int, used: ?int, remaining: ?int, unlimited: bool} */
    public function toArray(): array
    {
        return [
            'type' => $this->feature->type()->value,
            'enabled' => $this->enabled,
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'unlimited' => $this->unlimited(),
        ];
    }
}
