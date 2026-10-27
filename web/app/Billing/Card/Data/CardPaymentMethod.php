<?php

namespace App\Billing\Card\Data;

/** The card a subscription is charged to, as an owner recognises it. Never more than the brand and last four. */
final readonly class CardPaymentMethod
{
    public function __construct(
        public string $brand,
        public string $last4,
        public int $expMonth,
        public int $expYear,
        public ?string $wallet,
    ) {}
}
