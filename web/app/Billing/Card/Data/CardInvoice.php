<?php

namespace App\Billing\Card\Data;

use Carbon\CarbonImmutable;

/** A Stripe invoice: a receipt once paid. The payment fields say whether the bank asks the owner to confirm it. */
final readonly class CardInvoice
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $customerId,
        public CarbonImmutable $created,
        public string $amount,
        public string $currency,
        public string $status,
        public string $billingReason,
        public ?string $subscriptionId,
        public ?string $paymentIntentId,
        public ?string $paymentClientSecret,
        public ?string $paymentStatus,
    ) {}
}
