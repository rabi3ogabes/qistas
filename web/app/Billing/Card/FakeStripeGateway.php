<?php

namespace App\Billing\Card;

use App\Billing\Card\Data\CardAccount;
use App\Billing\Card\Data\CardInvoice;
use App\Billing\Card\Data\CardPaymentMethod;
use App\Billing\Card\Data\CardPreview;
use App\Billing\Card\Data\CardSession;
use App\Billing\Card\Data\CardSubscription;
use App\Billing\Card\Data\SubscriptionCheckout;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Stripe in memory, for tests (and, locally only, for looking at the pages without keys). It remembers every call,
 * keeps what was created, honours idempotency keys, can be told to fail the next call, and signs webhook messages the
 * way Stripe does, so the same verification code runs as in production.
 */
final class FakeStripeGateway implements StripeGateway
{
    use VerifiesStripeEvents;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $calls = [];

    /** @var array<string, string> lookup key => price id */
    private array $prices = [];

    /** @var array<string, array{plan: string, product: string, minor: int, currency: string, interval: string, lookup_key: string}> */
    private array $priceInfo = [];

    /** @var array<string, string> idempotency key => created id */
    private array $idempotent = [];

    /** @var array<string, array<string, string>> */
    private array $customers = [];

    /** @var array<string, CardSession> */
    private array $sessions = [];

    /** @var array<string, CardSubscription> */
    private array $subscriptions = [];

    /** @var array<string, CardPaymentMethod|null> subscription id => card */
    private array $cards = [];

    /** @var array<string, CardInvoice> */
    private array $invoices = [];

    /** @var array<string, string> invoice id => PDF bytes */
    private array $pdfs = [];

    /** @var array<string, CardPreview> subscription id => next charge */
    private array $upcoming = [];

    /** @var array<string, CardPreview> price id => preview of moving to it */
    private array $previews = [];

    /** @var array<string, array{url: string, events: list<string>, secret: string}> */
    private array $webhooks = [];

    /** @var list<string> */
    private array $domains = [];

    /** @var list<string> subscription ids whose last payment was refunded */
    private array $refunds = [];

    private ?Throwable $failNext = null;

    private int $sequence = 0;

    public CardAccount $account;

    public CardPaymentMethod $nextSetupCard;

    public function __construct()
    {
        $this->account = new CardAccount('acct_test_qistas', 'Qistas Ltd', 'AE', 'aed', true, false);
        $this->nextSetupCard = new CardPaymentMethod('mastercard', '4444', 3, 2031, null);
    }

    // What tests look at and arrange.

    /** @return list<array<int, mixed>> the arguments of every call to $method, oldest first */
    public function calls(string $method): array
    {
        return array_values(array_map(fn (array $call) => $call[1], array_filter($this->calls, fn (array $call) => $call[0] === $method)));
    }

    public function failNext(Throwable $e): void
    {
        $this->failNext = $e;
    }

    public function seedSubscription(CardSubscription $subscription): void
    {
        $this->subscriptions[$subscription->id] = $subscription;
    }

    /**
     * A subscription with sensible defaults (Pro, yearly, 120 USD, active until a year from now) and $overrides.
     *
     * @param  array<string, mixed>  $overrides  CardSubscription's constructor arguments by name
     */
    public static function subscriptionData(array $overrides = []): CardSubscription
    {
        $now = CarbonImmutable::now();

        return new CardSubscription(...$overrides + [
            'id' => 'sub_test_1',
            'customerId' => 'cus_test_1',
            'status' => 'active',
            'periodEnd' => $now->addYear(),
            'trialEnd' => null,
            'cancelAtPeriodEnd' => false,
            'priceId' => 'price_test_pro_year',
            'interval' => 'year',
            'amount' => '120.0000',
            'currency' => 'usd',
            'metadata' => ['plan' => 'pro', 'period' => 'year'],
            'scheduleId' => null,
            'scheduledPriceId' => null,
            'scheduledInterval' => null,
            'created' => $now,
            'latestInvoiceId' => null,
        ]);
    }

    /** The buyer paid: the session is complete and points at this subscription, which Stripe now holds. */
    public function completeSession(string $sessionId, CardSubscription $subscription): void
    {
        $session = $this->sessions[$sessionId];
        $this->sessions[$sessionId] = new CardSession($session->id, $session->clientSecret, 'complete', $session->mode, $session->customerId, $subscription->id, $session->setupIntentId, $session->metadata);
        $this->subscriptions[$subscription->id] = $subscription;
    }

    public function expireSession(string $sessionId): void
    {
        $session = $this->sessions[$sessionId];
        $this->sessions[$sessionId] = new CardSession($session->id, $session->clientSecret, 'expired', $session->mode, $session->customerId, null, $session->setupIntentId, $session->metadata);
    }

    public function seedInvoice(CardInvoice $invoice, string $pdf = '%PDF-1.4 fake receipt'): void
    {
        $this->invoices[$invoice->id] = $invoice;
        $this->pdfs[$invoice->id] = $pdf;
    }

    public function setUpcoming(string $subscriptionId, CardPreview $preview): void
    {
        $this->upcoming[$subscriptionId] = $preview;
    }

    public function setPreview(string $priceId, CardPreview $preview): void
    {
        $this->previews[$priceId] = $preview;
    }

    /** The card the subscription is charged to; null when Stripe holds none (paid by a wallet that saves no card). */
    public function setCard(string $subscriptionId, ?CardPaymentMethod $card): void
    {
        $this->cards[$subscriptionId] = $card;
    }

    /** @return array<string, string>|null what a customer was created with */
    public function customer(string $id): ?array
    {
        return $this->customers[$id] ?? null;
    }

    public function refunded(string $subscriptionId): bool
    {
        return in_array($subscriptionId, $this->refunds, true);
    }

    /** @return array{url: string, events: list<string>, secret: string}|null */
    public function webhook(string $id): ?array
    {
        return $this->webhooks[$id] ?? null;
    }

    /** @return list<string> */
    public function domains(): array
    {
        return $this->domains;
    }

    /** @return array{plan: string, product: string, minor: int, currency: string, interval: string, lookup_key: string}|null */
    public function price(string $priceId): ?array
    {
        return $this->priceInfo[$priceId] ?? null;
    }

    /**
     * A webhook message as Stripe sends it: the raw JSON and a Stripe-Signature header made with $secret.
     *
     * @param  array<string, mixed>  $object
     * @return array{payload: string, header: string}
     */
    public static function signedEvent(string $type, array $object, string $secret, ?string $id = null, bool $livemode = false, ?int $signedAt = null): array
    {
        $created = $signedAt ?? time();
        $payload = (string) json_encode([
            'id' => $id ?? 'evt_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'livemode' => $livemode,
            'created' => $created,
            'data' => ['object' => $object],
        ]);

        return ['payload' => $payload, 'header' => 't='.$created.',v1='.hash_hmac('sha256', $created.'.'.$payload, $secret)];
    }

    // The gateway.

    public function account(): CardAccount
    {
        $this->call(__FUNCTION__, []);

        return $this->account;
    }

    public function createWebhook(string $url, array $events): array
    {
        $this->call(__FUNCTION__, [$url, $events]);
        $id = $this->id('we');
        $this->webhooks[$id] = ['url' => $url, 'events' => $events, 'secret' => 'whsec_fake_'.$this->sequence];

        return ['id' => $id, 'secret' => $this->webhooks[$id]['secret']];
    }

    public function deleteWebhook(string $id): void
    {
        $this->call(__FUNCTION__, [$id]);
        unset($this->webhooks[$id]);
    }

    public function registerDomain(string $domain): void
    {
        $this->call(__FUNCTION__, [$domain]);
        $this->domains[] = $domain;
    }

    public function priceFor(string $lookupKey, string $planKey, string $productName, int $minor, string $currency, string $interval): string
    {
        $this->call(__FUNCTION__, [$lookupKey, $planKey, $productName, $minor, $currency, $interval]);
        if (isset($this->prices[$lookupKey])) {
            return $this->prices[$lookupKey];
        }

        $id = $this->id('price');
        $this->prices[$lookupKey] = $id;
        $this->priceInfo[$id] = ['plan' => $planKey, 'product' => $productName, 'minor' => $minor, 'currency' => $currency, 'interval' => $interval, 'lookup_key' => $lookupKey];

        return $id;
    }

    public function createCustomer(array $details, string $idempotencyKey): string
    {
        $this->call(__FUNCTION__, [$details, $idempotencyKey]);
        if (isset($this->idempotent[$idempotencyKey])) {
            return $this->idempotent[$idempotencyKey];
        }

        $id = $this->id('cus');
        $this->customers[$id] = $details;

        return $this->idempotent[$idempotencyKey] = $id;
    }

    public function createSubscriptionCheckout(SubscriptionCheckout $checkout, string $idempotencyKey): CardSession
    {
        $this->call(__FUNCTION__, [$checkout, $idempotencyKey]);
        if (isset($this->idempotent[$idempotencyKey])) {
            return $this->sessions[$this->idempotent[$idempotencyKey]];
        }

        $id = $this->id('cs_test');
        $this->sessions[$id] = new CardSession($id, $id.'_secret_fake', 'open', 'subscription', $checkout->customerId, null, null, $checkout->metadata);
        $this->idempotent[$idempotencyKey] = $id;

        return $this->sessions[$id];
    }

    public function createSetupCheckout(string $customerId, string $returnUrl, string $locale, array $metadata): CardSession
    {
        $this->call(__FUNCTION__, [$customerId, $returnUrl, $locale, $metadata]);
        $id = $this->id('cs_test');

        return $this->sessions[$id] = new CardSession($id, $id.'_secret_fake', 'open', 'setup', $customerId, null, $this->id('seti'), $metadata);
    }

    public function session(string $id): CardSession
    {
        $this->call(__FUNCTION__, [$id]);

        return $this->sessions[$id] ?? throw new CardRefused('No such checkout session.');
    }

    public function subscription(string $id): CardSubscription
    {
        $this->call(__FUNCTION__, [$id]);

        return $this->subscriptions[$id] ?? throw new CardRefused('No such subscription.');
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): CardSubscription
    {
        $this->call(__FUNCTION__, [$subscriptionId, $cancel]);

        return $this->subscriptions[$subscriptionId] = $this->with($this->held($subscriptionId), ['cancelAtPeriodEnd' => $cancel]);
    }

    public function changePriceNow(string $subscriptionId, string $priceId): CardSubscription
    {
        $this->call(__FUNCTION__, [$subscriptionId, $priceId]);
        $info = $this->priceInfo[$priceId] ?? throw new CardRefused('No such price.');

        return $this->subscriptions[$subscriptionId] = $this->with($this->held($subscriptionId), [
            'priceId' => $priceId, 'interval' => $info['interval'], 'amount' => StripeAmount::fromMinor($info['minor'], $info['currency']),
            'periodEnd' => $info['interval'] === 'year' ? CarbonImmutable::now()->addYear() : CarbonImmutable::now()->addMonth(),
            'scheduleId' => null, 'scheduledPriceId' => null, 'scheduledInterval' => null,
        ]);
    }

    public function schedulePriceChange(string $subscriptionId, string $priceId): CardSubscription
    {
        $this->call(__FUNCTION__, [$subscriptionId, $priceId]);
        $info = $this->priceInfo[$priceId] ?? throw new CardRefused('No such price.');

        return $this->subscriptions[$subscriptionId] = $this->with($this->held($subscriptionId), [
            'scheduleId' => $this->id('sub_sched'), 'scheduledPriceId' => $priceId, 'scheduledInterval' => $info['interval'],
        ]);
    }

    public function releaseSchedule(string $subscriptionId): CardSubscription
    {
        $this->call(__FUNCTION__, [$subscriptionId]);

        return $this->subscriptions[$subscriptionId] = $this->with($this->held($subscriptionId), ['scheduleId' => null, 'scheduledPriceId' => null, 'scheduledInterval' => null]);
    }

    public function previewPriceChange(string $subscriptionId, string $priceId): CardPreview
    {
        $this->call(__FUNCTION__, [$subscriptionId, $priceId]);
        if (isset($this->previews[$priceId])) {
            return $this->previews[$priceId];
        }
        $info = $this->priceInfo[$priceId] ?? throw new CardRefused('No such price.');

        return new CardPreview(StripeAmount::fromMinor($info['minor'], $info['currency']), $info['currency'], CarbonImmutable::now());
    }

    public function upcoming(string $subscriptionId): ?CardPreview
    {
        $this->call(__FUNCTION__, [$subscriptionId]);
        if (isset($this->upcoming[$subscriptionId])) {
            return $this->upcoming[$subscriptionId];
        }
        $subscription = $this->held($subscriptionId);

        return $subscription->cancelAtPeriodEnd || $subscription->periodEnd === null
            ? null
            : new CardPreview($subscription->amount, $subscription->currency, $subscription->trialEnd ?? $subscription->periodEnd);
    }

    public function paymentMethod(string $subscriptionId): ?CardPaymentMethod
    {
        $this->call(__FUNCTION__, [$subscriptionId]);

        return array_key_exists($subscriptionId, $this->cards) ? $this->cards[$subscriptionId] : new CardPaymentMethod('visa', '4242', 12, 2030, null);
    }

    public function useSetupPaymentMethod(string $setupIntentId, string $subscriptionId): void
    {
        $this->call(__FUNCTION__, [$setupIntentId, $subscriptionId]);
        $this->cards[$subscriptionId] = $this->nextSetupCard;
    }

    public function invoices(string $customerId, int $limit): array
    {
        $this->call(__FUNCTION__, [$customerId, $limit]);
        $mine = array_values(array_filter($this->invoices, fn (CardInvoice $invoice) => $invoice->customerId === $customerId));
        usort($mine, fn (CardInvoice $a, CardInvoice $b) => $b->created <=> $a->created);

        return array_slice($mine, 0, $limit);
    }

    public function invoice(string $id): CardInvoice
    {
        $this->call(__FUNCTION__, [$id]);

        return $this->invoices[$id] ?? throw new CardRefused('No such invoice.');
    }

    public function invoicePdf(string $id): string
    {
        $this->call(__FUNCTION__, [$id]);

        return $this->pdfs[$id] ?? throw new CardRefused('No such invoice.');
    }

    public function cancelNow(string $subscriptionId, bool $refundLatestPayment): void
    {
        $this->call(__FUNCTION__, [$subscriptionId, $refundLatestPayment]);
        $this->subscriptions[$subscriptionId] = $this->with($this->held($subscriptionId), ['status' => 'canceled', 'cancelAtPeriodEnd' => false]);
        if ($refundLatestPayment) {
            $this->refunds[] = $subscriptionId;
        }
    }

    /** @param  array<int, mixed>  $arguments */
    private function call(string $method, array $arguments): void
    {
        $this->calls[] = [$method, $arguments];
        if ($this->failNext !== null) {
            $failure = $this->failNext;
            $this->failNext = null;

            throw $failure;
        }
    }

    private function held(string $subscriptionId): CardSubscription
    {
        return $this->subscriptions[$subscriptionId] ?? throw new CardRefused('No such subscription.');
    }

    /** @param  array<string, mixed>  $changes */
    private function with(CardSubscription $subscription, array $changes): CardSubscription
    {
        return new CardSubscription(...$changes + get_object_vars($subscription));
    }

    private function id(string $prefix): string
    {
        return $prefix.'_fake_'.(++$this->sequence);
    }
}
