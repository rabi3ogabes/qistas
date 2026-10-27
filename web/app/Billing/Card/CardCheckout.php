<?php

namespace App\Billing\Card;

use App\Billing\BillingState;
use App\Billing\Card\Data\SubscriptionCheckout;
use App\Billing\Trials;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Opens a card checkout for a workspace (spec 2026-10-27 §4.1, §5, §9). Only its owner, only while the platform has
 * card payments on and set up, and never while the workspace already pays for a plan another way. A checkout already
 * open for the same offer is handed out again, and every new one is asked for with an idempotency key, so a double
 * press or a second tab never makes two payments.
 */
final class CardCheckout
{
    /** Stripe keeps a checkout open for 24 hours; one is reused for a little less. */
    private const REUSE_SECONDS = 23 * 3600;

    private const LOCALES = ['en' => 'en', 'ar' => 'ar', 'fr' => 'fr', 'es' => 'es', 'ur' => 'auto'];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly CardSettings $settings,
        private readonly StripePrices $prices,
        private readonly CardCustomers $customers,
        private readonly Trials $trials,
    ) {}

    /**
     * @return array{client_secret: string, publishable_key: string, trial_days: int}
     *
     * @throws CardRefused with the reason for the person
     * @throws CardUnavailable when Stripe could not be asked
     */
    public function start(Tenant $tenant, User $owner, string $planKey, string $period, string $attempt): array
    {
        $this->assertOpen($tenant, $owner);
        $plan = Plan::query()->where('key', $planKey)->where('is_default', false)->first()
            ?? throw new CardRefused(__('This plan cannot be bought by card at the moment.'));

        try {
            return Cache::lock('card-checkout:'.$tenant->id, 10)->block(5, function () use ($tenant, $owner, $plan, $period, $attempt): array {
                $priceId = $this->prices->for($plan, $period);
                $reuseKey = 'card-checkout:'.$tenant->id.':'.$this->settings->mode().':'.$this->prices->lookupKey($plan, $period);
                $trialDays = $this->trials->available($tenant) ? $this->settings->trialDays() : 0;

                $open = $this->openSession(Cache::get($reuseKey));
                if ($open !== null) {
                    return ['client_secret' => $open, 'publishable_key' => (string) $this->settings->publishableKey(), 'trial_days' => $trialDays];
                }

                $session = $this->stripe->createSubscriptionCheckout(new SubscriptionCheckout(
                    customerId: $this->customers->for($tenant, $owner),
                    priceId: $priceId,
                    trialDays: $trialDays > 0 ? $trialDays : null,
                    metadata: ['tenant_id' => $tenant->id, 'plan' => $plan->key, 'period' => $period],
                    promotionCodes: $this->settings->promotionCodes(),
                    collectTaxId: $this->settings->collectTaxId(),
                    automaticTax: $this->settings->automaticTax(),
                    locale: self::LOCALES[app()->getLocale()] ?? 'auto',
                    returnUrl: route('app.billing.card.return').'?session_id={CHECKOUT_SESSION_ID}',
                ), hash('sha256', $tenant->id.'|'.$plan->key.'|'.$period.'|'.$attempt));

                Cache::put($reuseKey, $session->id, self::REUSE_SECONDS);
                Audit::record('billing.card_checkout_started', changes: ['plan' => $plan->key, 'period' => $period], tenantId: $tenant->id, userId: $owner->id);

                return ['client_secret' => $session->clientSecret, 'publishable_key' => (string) $this->settings->publishableKey(), 'trial_days' => $trialDays];
            });
        } catch (LockTimeoutException) {
            throw new CardUnavailable('Another checkout for this workspace is being opened.');
        }
    }

    /**
     * Whether the owner may open a checkout now; the reason for the person when not.
     *
     * @throws CardRefused
     */
    public function assertOpen(Tenant $tenant, ?User $owner): void
    {
        if (! BillingState::canBuy($tenant, $owner)) {
            throw new CardRefused(__('Only the owner of the workspace can change its plan.'));
        }
        if (! Entitlements::for($tenant)->check(Feature::CardBilling)->enabled()) {
            throw new CardRefused(__('Card payments are switched off.'));
        }
        if (! $this->settings->ready()) {
            throw new CardRefused(__('Card payments are not set up yet.'));
        }
        if (BillingState::paidThrough($tenant) !== null) {
            throw new CardRefused(__('Pro is already on for this workspace.'));
        }
    }

    /** The client secret of the checkout still open under this id, or null. */
    private function openSession(mixed $sessionId): ?string
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        try {
            $session = $this->stripe->session($sessionId);
        } catch (CardRefused) {
            return null; // Stripe no longer knows it: open a new one
        }

        return $session->status === 'open' && $session->clientSecret !== '' ? $session->clientSecret : null;
    }
}
