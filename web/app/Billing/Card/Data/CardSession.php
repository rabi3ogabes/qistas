<?php

namespace App\Billing\Card\Data;

/** A Stripe Checkout Session: open, complete or expired; for a subscription or for saving a new card (setup). */
final readonly class CardSession
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public string $id,
        public string $clientSecret,
        public string $status,
        public string $mode,
        public ?string $customerId,
        public ?string $subscriptionId,
        public ?string $setupIntentId,
        public array $metadata,
    ) {}
}
