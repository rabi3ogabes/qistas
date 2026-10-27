<?php

namespace App\Billing\Card\Data;

/** Everything a subscription checkout is opened with. The price is a Stripe price id: never an amount from a browser. */
final readonly class SubscriptionCheckout
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public string $customerId,
        public string $priceId,
        public ?int $trialDays,
        public array $metadata,
        public bool $promotionCodes,
        public bool $collectTaxId,
        public bool $automaticTax,
        public string $locale,
        public string $returnUrl,
    ) {}
}
