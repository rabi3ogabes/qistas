<?php

namespace App\Billing\Card\Data;

use Carbon\CarbonImmutable;

/** A Stripe subscription as Qistas needs it. Money is a four-decimal string; the period end is the item's. */
final readonly class CardSubscription
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $status,
        public ?CarbonImmutable $periodEnd,
        public ?CarbonImmutable $trialEnd,
        public bool $cancelAtPeriodEnd,
        public string $priceId,
        public string $interval,
        public string $amount,
        public string $currency,
        public array $metadata,
        public ?string $scheduleId,
        public ?string $scheduledPriceId,
        public ?string $scheduledInterval,
        public CarbonImmutable $created,
        public ?string $latestInvoiceId,
    ) {}
}
