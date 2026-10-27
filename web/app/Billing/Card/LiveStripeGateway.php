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
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\Util\ApiVersion;
use Throwable;

/**
 * StripeGateway on stripe/stripe-php 22, pinned to the API version the library was built for (spec 2026-10-27 §5). It
 * reads Stripe's answers the way that version shapes them: a subscription's period end lives on its item, an invoice
 * names its subscription under parent.subscription_details, and the payment a bank wants confirmed is the invoice's
 * confirmation secret.
 */
final class LiveStripeGateway implements StripeGateway
{
    use VerifiesStripeEvents;

    private readonly StripeClient $stripe;

    public function __construct(CardSettings $settings, ?ClientInterface $http = null)
    {
        if ($http === null) {
            $curl = new CurlClient;
            $curl->setTimeout(20);
            $curl->setConnectTimeout(10);
            $http = $curl;
        }
        ApiRequestor::setHttpClient($http);

        $this->stripe = new StripeClient([
            'api_key' => (string) $settings->secretKey(),
            'stripe_version' => ApiVersion::CURRENT,
            'max_network_retries' => 3,
        ]);
    }

    public function account(): CardAccount
    {
        return $this->ask(function (): CardAccount {
            $account = $this->stripe->accounts->retrieve();

            return new CardAccount(
                (string) $account->id,
                $account->business_profile->name ?? $account->settings->dashboard->display_name ?? null,
                (string) ($account->country ?? ''),
                (string) ($account->default_currency ?? ''),
                (bool) ($account->charges_enabled ?? false),
                (bool) ($account->livemode ?? false),
            );
        });
    }

    public function createWebhook(string $url, array $events): array
    {
        return $this->ask(function () use ($url, $events): array {
            $endpoint = $this->stripe->webhookEndpoints->create([
                'url' => $url, 'enabled_events' => $events, 'api_version' => ApiVersion::CURRENT, 'description' => 'Qistas: Pro by card',
            ]);

            return ['id' => (string) $endpoint->id, 'secret' => (string) $endpoint->secret];
        });
    }

    public function deleteWebhook(string $id): void
    {
        $this->ask(fn () => $this->stripe->webhookEndpoints->delete($id));
    }

    public function registerDomain(string $domain): void
    {
        $this->ask(function () use ($domain): void {
            if ($this->stripe->paymentMethodDomains->all(['domain_name' => $domain, 'limit' => 1])->data !== []) {
                return;
            }
            $this->stripe->paymentMethodDomains->create(['domain_name' => $domain]);
        });
    }

    public function priceFor(string $lookupKey, string $planKey, string $productName, int $minor, string $currency, string $interval): string
    {
        return $this->ask(function () use ($lookupKey, $planKey, $productName, $minor, $currency, $interval): string {
            $found = $this->stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1])->data;
            if ($found !== []) {
                return (string) $found[0]->id;
            }

            $products = $this->stripe->products->search(['query' => "metadata['qistas_plan']:'{$planKey}' AND active:'true'"])->data;
            $productId = $products !== []
                ? (string) $products[0]->id
                : (string) $this->stripe->products->create(['name' => $productName, 'metadata' => ['qistas_plan' => $planKey]])->id;

            return (string) $this->stripe->prices->create([
                'product' => $productId, 'unit_amount' => $minor, 'currency' => strtolower($currency), 'recurring' => ['interval' => $interval],
                'tax_behavior' => 'inclusive', 'lookup_key' => $lookupKey, 'metadata' => ['qistas_plan' => $planKey],
            ])->id;
        });
    }

    public function createCustomer(array $details, string $idempotencyKey): string
    {
        return $this->ask(fn (): string => (string) $this->stripe->customers->create([
            'name' => $details['name'], 'email' => $details['email'], 'preferred_locales' => [$details['locale']], 'metadata' => ['tenant_id' => $details['tenant_id']],
        ], ['idempotency_key' => $idempotencyKey])->id);
    }

    public function createSubscriptionCheckout(SubscriptionCheckout $checkout, string $idempotencyKey): CardSession
    {
        $subscriptionData = ['metadata' => $checkout->metadata];
        if ($checkout->trialDays !== null && $checkout->trialDays > 0) {
            $subscriptionData['trial_period_days'] = $checkout->trialDays;
        }

        $params = [
            'ui_mode' => 'elements',
            'mode' => 'subscription',
            'customer' => $checkout->customerId,
            'client_reference_id' => $checkout->metadata['tenant_id'] ?? null,
            'line_items' => [['price' => $checkout->priceId, 'quantity' => 1]],
            'subscription_data' => $subscriptionData,
            'metadata' => $checkout->metadata,
            'locale' => $checkout->locale,
            'return_url' => $checkout->returnUrl,
        ];
        if ($checkout->promotionCodes) {
            $params['allow_promotion_codes'] = true;
        }
        if ($checkout->automaticTax) {
            $params['automatic_tax'] = ['enabled' => true];
        }
        if ($checkout->collectTaxId) {
            $params['tax_id_collection'] = ['enabled' => true];
        }
        if ($checkout->automaticTax || $checkout->collectTaxId) {
            // Tax needs the buyer's address and lets their business name and tax number sit on the receipt.
            $params['customer_update'] = ['name' => 'auto', 'address' => 'auto'];
            $params['billing_address_collection'] = 'required';
        }

        return $this->ask(fn (): CardSession => $this->session_($this->stripe->checkout->sessions->create(array_filter($params, fn ($v) => $v !== null), ['idempotency_key' => $idempotencyKey])));
    }

    public function createSetupCheckout(string $customerId, string $returnUrl, string $locale, array $metadata): CardSession
    {
        return $this->ask(fn (): CardSession => $this->session_($this->stripe->checkout->sessions->create([
            'ui_mode' => 'elements', 'mode' => 'setup', 'customer' => $customerId, 'payment_method_types' => ['card'],
            'locale' => $locale, 'return_url' => $returnUrl, 'metadata' => $metadata,
        ])));
    }

    public function session(string $id): CardSession
    {
        return $this->ask(fn (): CardSession => $this->session_($this->stripe->checkout->sessions->retrieve($id)));
    }

    public function subscription(string $id): CardSubscription
    {
        return $this->ask(fn (): CardSubscription => $this->subscription_($this->stripe->subscriptions->retrieve($id, ['expand' => ['default_payment_method']])));
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): CardSubscription
    {
        return $this->ask(fn (): CardSubscription => $this->subscription_($this->stripe->subscriptions->update($subscriptionId, ['cancel_at_period_end' => $cancel])));
    }

    public function changePriceNow(string $subscriptionId, string $priceId): CardSubscription
    {
        return $this->ask(function () use ($subscriptionId, $priceId): CardSubscription {
            $current = $this->stripe->subscriptions->retrieve($subscriptionId);
            // A subscription that a schedule manages cannot be changed directly: the pending change gives way.
            if (self::id($current->schedule ?? null) !== null) {
                $this->stripe->subscriptionSchedules->release((string) self::id($current->schedule));
            }

            return $this->subscription_($this->stripe->subscriptions->update($subscriptionId, [
                'items' => [['id' => (string) $current->items->data[0]->id, 'price' => $priceId]],
                'proration_behavior' => 'always_invoice', 'payment_behavior' => 'error_if_incomplete', 'cancel_at_period_end' => false,
            ]));
        });
    }

    public function schedulePriceChange(string $subscriptionId, string $priceId): CardSubscription
    {
        return $this->ask(function () use ($subscriptionId, $priceId): CardSubscription {
            $current = $this->stripe->subscriptions->retrieve($subscriptionId);
            $scheduleId = self::id($current->schedule ?? null);
            $schedule = $scheduleId !== null
                ? $this->stripe->subscriptionSchedules->retrieve($scheduleId)
                : $this->stripe->subscriptionSchedules->create(['from_subscription' => $subscriptionId]);
            $phase = $schedule->phases[0];
            $item = $current->items->data[0];

            $this->stripe->subscriptionSchedules->update((string) $schedule->id, [
                'end_behavior' => 'release',
                'phases' => [
                    ['items' => [['price' => (string) $item->price->id, 'quantity' => 1]], 'start_date' => (int) $phase->start_date, 'end_date' => (int) $phase->end_date, 'proration_behavior' => 'none'],
                    ['items' => [['price' => $priceId, 'quantity' => 1]], 'duration' => ['interval' => $this->intervalOf($priceId), 'interval_count' => 1], 'proration_behavior' => 'none'],
                ],
            ]);

            return $this->subscription_($this->stripe->subscriptions->retrieve($subscriptionId));
        });
    }

    public function releaseSchedule(string $subscriptionId): CardSubscription
    {
        return $this->ask(function () use ($subscriptionId): CardSubscription {
            $current = $this->stripe->subscriptions->retrieve($subscriptionId);
            $scheduleId = self::id($current->schedule ?? null);
            if ($scheduleId !== null) {
                $this->stripe->subscriptionSchedules->release($scheduleId);
                $current = $this->stripe->subscriptions->retrieve($subscriptionId);
            }

            return $this->subscription_($current);
        });
    }

    public function previewPriceChange(string $subscriptionId, string $priceId): CardPreview
    {
        return $this->ask(function () use ($subscriptionId, $priceId): CardPreview {
            $current = $this->stripe->subscriptions->retrieve($subscriptionId);
            $preview = $this->stripe->invoices->createPreview([
                'customer' => (string) self::id($current->customer),
                'subscription' => $subscriptionId,
                'subscription_details' => ['items' => [['id' => (string) $current->items->data[0]->id, 'price' => $priceId]], 'proration_behavior' => 'always_invoice'],
            ]);

            return new CardPreview(StripeAmount::fromMinor((int) $preview->amount_due, (string) $preview->currency), (string) $preview->currency, CarbonImmutable::now());
        });
    }

    public function upcoming(string $subscriptionId): ?CardPreview
    {
        return $this->ask(function () use ($subscriptionId): ?CardPreview {
            $subscription = $this->subscription_($this->stripe->subscriptions->retrieve($subscriptionId));
            if ($subscription->cancelAtPeriodEnd || ! in_array($subscription->status, ['trialing', 'active', 'past_due'], true)) {
                return null;
            }

            $preview = $this->stripe->invoices->createPreview(['customer' => $subscription->customerId, 'subscription' => $subscriptionId]);
            $when = $subscription->trialEnd ?? $subscription->periodEnd ?? CarbonImmutable::now();

            return new CardPreview(StripeAmount::fromMinor((int) $preview->amount_due, (string) $preview->currency), (string) $preview->currency, $when);
        });
    }

    public function paymentMethod(string $subscriptionId): ?CardPaymentMethod
    {
        return $this->ask(function () use ($subscriptionId): ?CardPaymentMethod {
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['default_payment_method']]);
            $method = $subscription->default_payment_method ?? null;
            $card = $method instanceof StripeObject ? ($method->card ?? null) : null;
            if ($card === null) {
                return null;
            }

            return new CardPaymentMethod((string) $card->brand, (string) $card->last4, (int) $card->exp_month, (int) $card->exp_year, $card->wallet->type ?? null);
        });
    }

    public function useSetupPaymentMethod(string $setupIntentId, string $subscriptionId): void
    {
        $this->ask(function () use ($setupIntentId, $subscriptionId): void {
            $intent = $this->stripe->setupIntents->retrieve($setupIntentId);
            $method = (string) self::id($intent->payment_method);
            $this->stripe->subscriptions->update($subscriptionId, ['default_payment_method' => $method]);
            if (self::id($intent->customer ?? null) !== null) {
                $this->stripe->customers->update((string) self::id($intent->customer), ['invoice_settings' => ['default_payment_method' => $method]]);
            }
        });
    }

    public function invoices(string $customerId, int $limit): array
    {
        return $this->ask(fn (): array => array_map(
            fn (Invoice $invoice) => $this->invoice_($invoice),
            $this->stripe->invoices->all(['customer' => $customerId, 'limit' => $limit])->data,
        ));
    }

    public function invoice(string $id): CardInvoice
    {
        return $this->ask(fn (): CardInvoice => $this->invoice_($this->stripe->invoices->retrieve($id, ['expand' => ['confirmation_secret', 'payments.data.payment.payment_intent']])));
    }

    public function invoicePdf(string $id): string
    {
        $link = $this->ask(fn () => $this->stripe->invoices->retrieve($id)->invoice_pdf ?? null);
        if (! is_string($link) || $link === '') {
            throw new CardRefused(__('This receipt is not ready yet.'));
        }

        try {
            return Http::timeout(20)->get($link)->throw()->body();
        } catch (Throwable $e) {
            Log::warning('stripe invoice pdf failed', ['error' => $e::class]);

            throw new CardUnavailable('The receipt could not be downloaded.', previous: $e);
        }
    }

    public function cancelNow(string $subscriptionId, bool $refundLatestPayment): void
    {
        $this->ask(function () use ($subscriptionId, $refundLatestPayment): void {
            // First stop every future charge; then give the last payment back.
            $subscription = $this->stripe->subscriptions->cancel($subscriptionId, ['prorate' => false]);
            if (! $refundLatestPayment) {
                return;
            }

            $invoiceId = self::id($subscription->latest_invoice ?? null) ?? self::id($this->stripe->subscriptions->retrieve($subscriptionId)->latest_invoice ?? null);
            if ($invoiceId === null) {
                return;
            }
            foreach ($this->stripe->invoices->retrieve($invoiceId, ['expand' => ['payments']])->payments->data ?? [] as $payment) {
                if (($payment->status ?? null) === 'paid' && ($payment->payment->type ?? null) === 'payment_intent') {
                    $this->stripe->refunds->create(['payment_intent' => (string) self::id($payment->payment->payment_intent ?? null)]);
                }
            }
        });
    }

    /**
     * Runs one conversation with Stripe and says what went wrong in Qistas' terms.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function ask(Closure $call): mixed
    {
        try {
            return $call();
        } catch (CardException $e) {
            throw new CardRefused($e->getMessage(), previous: $e);
        } catch (RateLimitException|AuthenticationException|PermissionException $e) {
            Log::warning('stripe refused the platform', ['error' => $e::class, 'request' => $e->getRequestId()]);

            throw new CardUnavailable('Card payments are briefly unavailable.', previous: $e);
        } catch (InvalidRequestException|IdempotencyException $e) {
            Log::warning('stripe refused a request', ['error' => $e::class, 'code' => $e->getStripeCode(), 'request' => $e->getRequestId()]);

            throw new CardRefused($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            Log::warning('stripe unavailable', ['error' => $e::class, 'request' => $e->getRequestId()]);

            throw new CardUnavailable('Card payments are briefly unavailable.', previous: $e);
        }
    }

    private function session_(StripeObject $session): CardSession
    {
        return new CardSession(
            (string) $session->id,
            (string) ($session->client_secret ?? ''),
            (string) ($session->status ?? 'open'),
            (string) ($session->mode ?? 'subscription'),
            self::id($session->customer ?? null),
            self::id($session->subscription ?? null),
            self::id($session->setup_intent ?? null),
            self::metadata($session->metadata ?? null),
        );
    }

    private function subscription_(Subscription $subscription): CardSubscription
    {
        $item = $subscription->items->data[0];
        $price = $item->price;
        $scheduleId = self::id($subscription->schedule ?? null);
        [$scheduledPrice, $scheduledInterval] = $scheduleId === null ? [null, null] : $this->nextPhase($scheduleId, (int) $item->current_period_end);

        return new CardSubscription(
            (string) $subscription->id,
            (string) self::id($subscription->customer),
            (string) $subscription->status,
            isset($item->current_period_end) ? CarbonImmutable::createFromTimestamp((int) $item->current_period_end) : null,
            isset($subscription->trial_end) ? CarbonImmutable::createFromTimestamp((int) $subscription->trial_end) : null,
            (bool) ($subscription->cancel_at_period_end ?? false),
            (string) $price->id,
            (string) ($price->recurring->interval ?? 'month'),
            StripeAmount::fromMinor((int) ($price->unit_amount ?? 0), (string) ($price->currency ?? 'usd')),
            (string) ($price->currency ?? 'usd'),
            self::metadata($subscription->metadata ?? null),
            $scheduleId,
            $scheduledPrice,
            $scheduledInterval,
            CarbonImmutable::createFromTimestamp((int) ($subscription->created ?? time())),
            self::id($subscription->latest_invoice ?? null),
        );
    }

    /** @return array{0: string|null, 1: string|null} the price and interval the schedule moves to after $periodEnd */
    private function nextPhase(string $scheduleId, int $periodEnd): array
    {
        foreach ($this->stripe->subscriptionSchedules->retrieve($scheduleId)->phases ?? [] as $phase) {
            if ((int) $phase->start_date >= $periodEnd) {
                $price = self::id($phase->items[0]->price ?? null);

                return [$price, $price === null ? null : $this->intervalOf($price)];
            }
        }

        return [null, null];
    }

    private function intervalOf(string $priceId): string
    {
        return (string) ($this->stripe->prices->retrieve($priceId)->recurring->interval ?? 'month');
    }

    private function invoice_(Invoice $invoice): CardInvoice
    {
        $intent = null;
        foreach ($invoice->payments->data ?? [] as $payment) {
            if (($payment->payment->type ?? null) === 'payment_intent') {
                $intent = $payment->payment->payment_intent ?? null;
            }
        }
        $currency = (string) ($invoice->currency ?? 'usd');
        $amount = ($invoice->status ?? null) === 'paid' ? (int) ($invoice->amount_paid ?? 0) : (int) ($invoice->amount_due ?? 0);

        return new CardInvoice(
            (string) $invoice->id,
            $invoice->number ?? null,
            (string) self::id($invoice->customer ?? null),
            CarbonImmutable::createFromTimestamp((int) ($invoice->created ?? time())),
            StripeAmount::fromMinor($amount, $currency),
            $currency,
            (string) ($invoice->status ?? 'draft'),
            (string) ($invoice->billing_reason ?? ''),
            self::id($invoice->parent->subscription_details->subscription ?? null),
            self::id($intent),
            $invoice->confirmation_secret->client_secret ?? null,
            $intent instanceof StripeObject ? ($intent->status ?? null) : null,
        );
    }

    /** An id, whether Stripe sent the id or the expanded object. */
    private static function id(mixed $value): ?string
    {
        return match (true) {
            is_string($value) && $value !== '' => $value,
            $value instanceof StripeObject && isset($value->id) => (string) $value->id,
            default => null,
        };
    }

    /** @return array<string, string> */
    private static function metadata(mixed $metadata): array
    {
        return $metadata instanceof StripeObject ? array_map('strval', $metadata->toArray()) : [];
    }
}
