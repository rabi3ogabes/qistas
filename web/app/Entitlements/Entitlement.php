<?php

namespace App\Entitlements;

/** What one workspace may do with one feature right now. Immutable. */
final readonly class Entitlement
{
    /**
     * @param  ?int  $limit  null = unlimited (for counted features) or not applicable (on/off features)
     * @param  ?int  $used  null for on/off features
     * @param  ?string  $detail  why, when it is not obvious: `dependency:<key>` when a feature this one needs is the reason
     */
    public function __construct(
        public Feature $feature,
        private FeatureStatus $status,
        private ?string $detail,
        private ?int $limit,
        private ?int $used,
    ) {}

    public function status(): FeatureStatus
    {
        return $this->status;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function enabled(): bool
    {
        return $this->status === FeatureStatus::On;
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
        return $this->enabled() && $this->feature->type() !== FeatureType::Toggle && $this->limit === null;
    }

    /** How many more may be added; null when there is no cap to count down from. Never negative. */
    public function remaining(): ?int
    {
        if ($this->feature->type() === FeatureType::Toggle) {
            return null;
        }
        if (! $this->enabled()) {
            return 0;
        }

        return $this->limit === null ? null : max(0, $this->limit - (int) $this->used);
    }

    public function allows(int $amount = 1): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->limit === null || (int) $this->used + $amount <= $this->limit;
    }

    /** @return array{type: string, status: string, detail: ?string, enabled: bool, limit: ?int, used: ?int, remaining: ?int, unlimited: bool} */
    public function toArray(): array
    {
        return [
            'type' => $this->feature->type()->value,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'enabled' => $this->enabled(),
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining(),
            'unlimited' => $this->unlimited(),
        ];
    }
}
