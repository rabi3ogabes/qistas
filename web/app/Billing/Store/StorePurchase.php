<?php

namespace App\Billing\Store;

use Carbon\CarbonImmutable;

/**
 * What a store says about one subscription purchase, in our words (Win Plan PP13). The store is the only source of
 * this: nothing a phone sends is taken as true.
 *
 * state: active (paid, or in a free trial), grace (a renewal failed, Google still gives access), canceled (renewal
 * turned off, paid time left), hold, paused, expired, pending (payment not complete), revoked (refunded).
 */
final class StorePurchase
{
    public const STATES = ['active', 'grace', 'canceled', 'hold', 'paused', 'expired', 'pending', 'revoked'];

    public function __construct(
        public readonly string $productId,
        public readonly string $state,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly bool $autoRenew,
        public readonly bool $acknowledged,
        public readonly ?string $accountRef,
        public readonly ?string $linkedToken = null,
        public readonly bool $trial = false,
        public readonly string $storeStatus = '',
    ) {}

    /** The purchase gives the plan right now. */
    public function grantsAccess(): bool
    {
        return in_array($this->state, ['active', 'grace', 'canceled'], true)
            && ($this->expiresAt === null || $this->expiresAt->isFuture());
    }

    public function acknowledgedCopy(): self
    {
        return new self($this->productId, $this->state, $this->expiresAt, $this->autoRenew, true, $this->accountRef, $this->linkedToken, $this->trial, $this->storeStatus);
    }
}
