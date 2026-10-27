<?php

namespace App\Billing\Card;

use App\Billing\Card\Data\CardAccount;
use App\Billing\Card\Data\CardEvent;
use App\Billing\Card\Data\CardInvoice;
use App\Billing\Card\Data\CardPaymentMethod;
use App\Billing\Card\Data\CardPreview;
use App\Billing\Card\Data\CardSession;
use App\Billing\Card\Data\CardSubscription;
use App\Billing\Card\Data\SubscriptionCheckout;

/**
 * Everything Qistas asks Stripe, in Qistas' terms (spec 2026-10-27 §5). The only code that talks to Stripe implements
 * this; tests use FakeStripeGateway. Every method throws CardUnavailable when Stripe cannot be asked, and CardRefused
 * when Stripe refuses with a message meant for the person (a declined card, an invalid promotion code).
 */
interface StripeGateway
{
    public function account(): CardAccount;

    /**
     * A webhook endpoint for this site, sending the given events, pinned to the library's API version.
     *
     * @param  list<string>  $events
     * @return array{id: string, secret: string} the endpoint and its signing secret (Stripe shows the secret only once)
     */
    public function createWebhook(string $url, array $events): array;

    public function deleteWebhook(string $id): void;

    /** Lets Apple Pay and Google Pay appear on pages of this domain. */
    public function registerDomain(string $domain): void;

    /** The active price with this lookup key, created (with its product, found by metadata qistas_plan) if missing. */
    public function priceFor(string $lookupKey, string $planKey, string $productName, int $minor, string $currency, string $interval): string;

    /** @param  array{name: string, email: string, locale: string, tenant_id: string}  $details */
    public function createCustomer(array $details, string $idempotencyKey): string;

    public function createSubscriptionCheckout(SubscriptionCheckout $checkout, string $idempotencyKey): CardSession;

    /** @param  array<string, string>  $metadata */
    public function createSetupCheckout(string $customerId, string $returnUrl, string $locale, array $metadata): CardSession;

    public function session(string $id): CardSession;

    public function subscription(string $id): CardSubscription;

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): CardSubscription;

    /** Moves to the price now, charging the difference at once (the unused time is credited). */
    public function changePriceNow(string $subscriptionId, string $priceId): CardSubscription;

    /** Moves to the price at the end of the period already paid. */
    public function schedulePriceChange(string $subscriptionId, string $priceId): CardSubscription;

    /** Drops a scheduled change: the subscription renews as it is. */
    public function releaseSchedule(string $subscriptionId): CardSubscription;

    public function previewPriceChange(string $subscriptionId, string $priceId): CardPreview;

    /** What the next renewal will charge, after discounts; null when nothing is coming (cancelled at period end). */
    public function upcoming(string $subscriptionId): ?CardPreview;

    public function paymentMethod(string $subscriptionId): ?CardPaymentMethod;

    /** Makes the card saved by a setup session the one the subscription is charged to. */
    public function useSetupPaymentMethod(string $setupIntentId, string $subscriptionId): void;

    /** @return list<CardInvoice> newest first */
    public function invoices(string $customerId, int $limit): array;

    public function invoice(string $id): CardInvoice;

    /** The invoice's PDF, as bytes. */
    public function invoicePdf(string $id): string;

    /**
     * Ends the subscription now; with $refundLatestPayment, the last payment is refunded: in full, or with
     * $onlyUnusedTime the share of it for the time left in the period.
     */
    public function cancelNow(string $subscriptionId, bool $refundLatestPayment, bool $onlyUnusedTime = false): void;

    /** @throws CardSignatureInvalid when Stripe did not sign the payload with this secret within five minutes */
    public function event(string $payload, string $signatureHeader, string $secret): CardEvent;
}
