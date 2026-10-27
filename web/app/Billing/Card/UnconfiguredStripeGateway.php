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

/** Bound while no Stripe key is saved: every question is answered "not set up yet", nothing is ever sent anywhere. */
final class UnconfiguredStripeGateway implements StripeGateway
{
    public function account(): CardAccount
    {
        throw $this->unavailable();
    }

    public function createWebhook(string $url, array $events): array
    {
        throw $this->unavailable();
    }

    public function deleteWebhook(string $id): void
    {
        throw $this->unavailable();
    }

    public function registerDomain(string $domain): void
    {
        throw $this->unavailable();
    }

    public function priceFor(string $lookupKey, string $planKey, string $productName, int $minor, string $currency, string $interval): string
    {
        throw $this->unavailable();
    }

    public function createCustomer(array $details, string $idempotencyKey): string
    {
        throw $this->unavailable();
    }

    public function createSubscriptionCheckout(SubscriptionCheckout $checkout, string $idempotencyKey): CardSession
    {
        throw $this->unavailable();
    }

    public function createSetupCheckout(string $customerId, string $returnUrl, string $locale, array $metadata): CardSession
    {
        throw $this->unavailable();
    }

    public function session(string $id): CardSession
    {
        throw $this->unavailable();
    }

    public function subscription(string $id): CardSubscription
    {
        throw $this->unavailable();
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): CardSubscription
    {
        throw $this->unavailable();
    }

    public function changePriceNow(string $subscriptionId, string $priceId): CardSubscription
    {
        throw $this->unavailable();
    }

    public function schedulePriceChange(string $subscriptionId, string $priceId): CardSubscription
    {
        throw $this->unavailable();
    }

    public function releaseSchedule(string $subscriptionId): CardSubscription
    {
        throw $this->unavailable();
    }

    public function previewPriceChange(string $subscriptionId, string $priceId): CardPreview
    {
        throw $this->unavailable();
    }

    public function upcoming(string $subscriptionId): ?CardPreview
    {
        throw $this->unavailable();
    }

    public function paymentMethod(string $subscriptionId): ?CardPaymentMethod
    {
        throw $this->unavailable();
    }

    public function useSetupPaymentMethod(string $setupIntentId, string $subscriptionId): void
    {
        throw $this->unavailable();
    }

    public function invoices(string $customerId, int $limit): array
    {
        throw $this->unavailable();
    }

    public function invoice(string $id): CardInvoice
    {
        throw $this->unavailable();
    }

    public function invoicePdf(string $id): string
    {
        throw $this->unavailable();
    }

    public function cancelNow(string $subscriptionId, bool $refundLatestPayment, bool $onlyUnusedTime = false): void
    {
        throw $this->unavailable();
    }

    public function event(string $payload, string $signatureHeader, string $secret): CardEvent
    {
        throw $this->unavailable();
    }

    private function unavailable(): CardUnavailable
    {
        return new CardUnavailable('Card payments are not set up yet.');
    }
}
