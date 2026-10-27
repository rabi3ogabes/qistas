<?php

namespace App\Billing\Card\Data;

/** The platform owner's Stripe account, as the admin panel shows it after Connect. */
final readonly class CardAccount
{
    public function __construct(
        public string $id,
        public ?string $name,
        public string $country,
        public string $currency,
        public bool $chargesEnabled,
        public bool $livemode,
    ) {}
}
