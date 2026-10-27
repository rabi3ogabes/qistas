<?php

namespace App\Billing\Card\Data;

use Carbon\CarbonImmutable;

/** A webhook message whose signature was checked: its id, type and the object it is about (Stripe's data.object). */
final readonly class CardEvent
{
    /** @param  array<string, mixed>  $object */
    public function __construct(
        public string $id,
        public string $type,
        public array $object,
        public bool $livemode,
        public CarbonImmutable $created,
    ) {}
}
