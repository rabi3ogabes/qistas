<?php

namespace App\Billing\Card;

use App\Billing\Card\Data\CardEvent;
use Carbon\CarbonImmutable;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * One way to read a webhook message, shared by the live gateway and the fake one: Stripe's own library checks the
 * Stripe-Signature header against the raw body with this site's secret, allowing five minutes of clock difference.
 */
trait VerifiesStripeEvents
{
    public function event(string $payload, string $signatureHeader, string $secret): CardEvent
    {
        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret, 300);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new CardSignatureInvalid($e->getMessage(), previous: $e);
        }

        $object = $event->data->object ?? null;

        return new CardEvent(
            (string) $event->id,
            (string) $event->type,
            $object === null ? [] : $object->toArray(),
            (bool) $event->livemode,
            CarbonImmutable::createFromTimestamp((int) $event->created),
        );
    }
}
