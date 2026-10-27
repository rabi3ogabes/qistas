<?php

namespace App\Billing\Card\Data;

use Carbon\CarbonImmutable;

/** What Stripe would charge next, as its invoice preview says: the amount after credits and discounts, and when. */
final readonly class CardPreview
{
    public function __construct(
        public string $amountDue,
        public string $currency,
        public CarbonImmutable $nextCharge,
    ) {}
}
