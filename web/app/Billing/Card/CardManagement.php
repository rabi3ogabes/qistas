<?php

namespace App\Billing\Card;

use App\Billing\Card\Data\CardInvoice;
use App\Billing\Card\Data\CardPaymentMethod;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * The owner managing their card subscription inside Qistas (spec 2026-10-27 §4.3): the card on file and a new one,
 * monthly or yearly, cancelling and resuming, receipts, and a payment the bank holds. Everything is read from Stripe
 * when shown, never copied; every action works only on the workspace's own subscription, customer and invoices.
 */
final class CardManagement
{
    public const CANCEL_REASONS = ['too_expensive', 'missing_feature', 'not_using', 'switching', 'other'];

    /** What the billing page shows is read from Stripe at most once a minute, and again after any change. */
    private const OVERVIEW_SECONDS = 60;

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly CardSettings $settings,
        private readonly StripePrices $prices,
        private readonly CardSubscriptions $subscriptions,
    ) {}

    public static function cacheKey(string $subscriptionId): string
    {
        return 'card-overview:'.$subscriptionId;
    }

    /**
     * Where the card subscription stands, for the billing page; null when the workspace does not pay by card.
     *
     * @return array{status: string, until: ?CarbonImmutable, trial_end: ?CarbonImmutable, cancel_at_period_end: bool, period: string, next_amount: ?string, currency: string, scheduled_period: ?string, card: ?CardPaymentMethod, needs_action: bool}|null
     */
    public function overview(Tenant $tenant): ?array
    {
        $local = $this->local($tenant);
        if ($local === null) {
            return null;
        }
        $id = (string) $local->stripe_subscription_id;

        return Cache::remember(self::cacheKey($id), self::OVERVIEW_SECONDS, function () use ($id): array {
            $remote = $this->stripe->subscription($id);
            $next = $this->stripe->upcoming($id);

            return [
                'status' => $remote->status,
                'until' => $remote->periodEnd,
                'trial_end' => $remote->status === 'trialing' ? $remote->trialEnd : null,
                'cancel_at_period_end' => $remote->cancelAtPeriodEnd,
                'period' => $remote->interval,
                'next_amount' => $next?->amountDue,
                'currency' => $next->currency ?? $remote->currency,
                'scheduled_period' => $remote->scheduledInterval,
                'card' => $this->stripe->paymentMethod($id),
                'needs_action' => $this->held($remote->latestInvoiceId) !== null,
            ];
        });
    }

    /**
     * A setup checkout to save a new card.
     *
     * @return array{client_secret: string, publishable_key: string}
     */
    public function startCardChange(Tenant $tenant): array
    {
        $this->current($tenant);
        $session = $this->stripe->createSetupCheckout(
            (string) $tenant->stripe_customer_id,
            route('app.billing.card.setup-return').'?session_id={CHECKOUT_SESSION_ID}',
            ['en' => 'en', 'ar' => 'ar', 'fr' => 'fr', 'es' => 'es'][app()->getLocale()] ?? 'auto',
            ['tenant_id' => $tenant->id, 'purpose' => 'card_change'],
        );

        return ['client_secret' => $session->clientSecret, 'publishable_key' => (string) $this->settings->publishableKey()];
    }

    /**
     * Back from the setup checkout: the saved card becomes the one charged. False when nothing was saved.
     *
     * @throws ModelNotFoundException when the session is another workspace's
     */
    public function finishCardChange(Tenant $tenant, string $sessionId, User $by): bool
    {
        $local = $this->current($tenant);
        $session = $this->stripe->session($sessionId);
        if ($session->customerId === null || $session->customerId !== $tenant->stripe_customer_id) {
            throw (new ModelNotFoundException)->setModel(Subscription::class);
        }
        if ($session->status !== 'complete' || $session->setupIntentId === null) {
            return false;
        }

        $this->stripe->useSetupPaymentMethod($session->setupIntentId, (string) $local->stripe_subscription_id);
        $this->changed($local);
        Audit::record('billing.card_changed', $local, [], $tenant->id, $by->id);

        return true;
    }

    /**
     * What a switch would cost: to yearly, Stripe's own preview of the charge now; to monthly, the monthly price from
     * the end of the paid year; back to the period already running (a switch pending), nothing changes.
     *
     * @return array{amount_due: string, currency: string, when: string, date: string}
     */
    public function previewSwitch(Tenant $tenant, string $period): array
    {
        $local = $this->current($tenant);
        $remote = $this->stripe->subscription((string) $local->stripe_subscription_id);
        $plan = $local->plan;

        if ($period === $remote->interval) {
            return ['amount_due' => $remote->amount, 'currency' => $remote->currency, 'when' => 'keep', 'date' => (string) $remote->periodEnd?->toDateString()];
        }
        if ($period === 'year') {
            $preview = $this->stripe->previewPriceChange($remote->id, $this->prices->for($plan, 'year'));

            return ['amount_due' => $preview->amountDue, 'currency' => $preview->currency, 'when' => 'now', 'date' => $preview->nextCharge->toDateString()];
        }

        return ['amount_due' => (string) $plan->price_monthly, 'currency' => $plan->currency, 'when' => 'period_end', 'date' => (string) $remote->periodEnd?->toDateString()];
    }

    /**
     * Monthly to yearly now (the unused month is credited); yearly to monthly from the end of the paid year; back to the
     * period already running drops a pending switch.
     *
     * @return string now, period_end or keep
     */
    public function switchPeriod(Tenant $tenant, string $period, User $by): string
    {
        $local = $this->current($tenant);
        $remote = $this->stripe->subscription((string) $local->stripe_subscription_id);

        if ($period === $remote->interval) {
            if ($remote->scheduleId === null) {
                throw new CardRefused(__('Pro already renews this way.'));
            }
            $this->stripe->releaseSchedule($remote->id);
            $when = 'keep';
        } elseif ($period === 'year') {
            $this->stripe->changePriceNow($remote->id, $this->prices->for($local->plan, 'year'));
            $when = 'now';
        } else {
            $this->stripe->schedulePriceChange($remote->id, $this->prices->for($local->plan, 'month'));
            $when = 'period_end';
        }

        $this->changed($local);
        Audit::record('billing.card_period_switched', $local, ['from' => $remote->interval, 'to' => $period, 'when' => $when], $tenant->id, $by->id);

        return $when;
    }

    /** Renewal off: the plan stays to the end of the period paid; the reason is kept in the activity log only. */
    public function cancel(Tenant $tenant, ?string $reason, ?string $details, User $by): Subscription
    {
        $local = $this->current($tenant);
        $this->stripe->setCancelAtPeriodEnd((string) $local->stripe_subscription_id, true);
        $synced = $this->changed($local);
        Audit::record('billing.card_cancelled', $local, ['reason' => $reason, 'details' => $details], $tenant->id, $by->id);

        return $synced;
    }

    /**
     * The platform team ends a workspace's card subscription (spec 2026-10-27 §8): now, refunding the part of the last
     * payment not used yet, which puts the workspace on Free with nothing deleted; or at the end of the period.
     *
     * @param  string  $when  now or period_end
     *
     * @throws CardRefused when the workspace has no card subscription running
     */
    public function cancelForPlatform(Tenant $tenant, string $when, User $by): Subscription
    {
        $local = $this->current($tenant);
        $id = (string) $local->stripe_subscription_id;
        if ($when === 'now') {
            $this->stripe->cancelNow($id, true, true);
        } else {
            $this->stripe->setCancelAtPeriodEnd($id, true);
        }
        $synced = $this->changed($local);
        Audit::record('admin.card_subscription_cancelled', $local, ['when' => $when], $tenant->id, $by->id);

        return $synced;
    }

    public function resume(Tenant $tenant, User $by): Subscription
    {
        $local = $this->current($tenant);
        $this->stripe->setCancelAtPeriodEnd((string) $local->stripe_subscription_id, false);
        $synced = $this->changed($local);
        Audit::record('billing.card_resumed', $local, [], $tenant->id, $by->id);

        return $synced;
    }

    /** @return list<CardInvoice> the workspace's charges, newest first; a trial's opening zero is not a receipt */
    public function receipts(Tenant $tenant): array
    {
        if ($tenant->stripe_customer_id === null) {
            return [];
        }

        return array_values(array_filter(
            $this->stripe->invoices($tenant->stripe_customer_id, 24),
            fn (CardInvoice $invoice) => $invoice->customerId === $tenant->stripe_customer_id && in_array($invoice->status, ['paid', 'open'], true) && bccomp($invoice->amount, '0', 4) > 0,
        ));
    }

    /**
     * @return array{name: string, bytes: string}
     *
     * @throws ModelNotFoundException when the invoice is not the workspace's
     * @throws CardUnavailable when Stripe or the PDF could not be reached
     */
    public function receiptPdf(Tenant $tenant, string $invoiceId): array
    {
        try {
            $invoice = $this->stripe->invoice($invoiceId);
        } catch (CardRefused) {
            throw (new ModelNotFoundException)->setModel(Subscription::class);
        }
        if ($tenant->stripe_customer_id === null || $invoice->customerId !== $tenant->stripe_customer_id) {
            throw (new ModelNotFoundException)->setModel(Subscription::class);
        }

        $name = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($invoice->number ?? $invoice->id)) ?: 'receipt';

        return ['name' => 'qistas-receipt-'.$name.'.pdf', 'bytes' => $this->stripe->invoicePdf($invoice->id)];
    }

    /**
     * The payment the bank asks the owner to confirm, for Stripe.js on the billing page; null when there is none.
     *
     * @return array{client_secret: string, publishable_key: string}|null
     */
    public function paymentToConfirm(Tenant $tenant): ?array
    {
        $local = $this->current($tenant);
        $held = $this->held($this->stripe->subscription((string) $local->stripe_subscription_id)->latestInvoiceId);

        return $held === null ? null : ['client_secret' => $held, 'publishable_key' => (string) $this->settings->publishableKey()];
    }

    /** The client secret of an open invoice whose payment waits for the bank's check; null otherwise. */
    private function held(?string $invoiceId): ?string
    {
        if ($invoiceId === null) {
            return null;
        }
        $invoice = $this->stripe->invoice($invoiceId);

        return $invoice->status === 'open' && $invoice->paymentStatus === 'requires_action' ? $invoice->paymentClientSecret : null;
    }

    /** The workspace's card subscription in force, or a refusal for the person. */
    private function current(Tenant $tenant): Subscription
    {
        return $this->local($tenant) ?? throw new CardRefused(__('There is no card subscription to change.'));
    }

    private function local(Tenant $tenant): ?Subscription
    {
        $subscription = $tenant->subscription()->with('plan')->first();

        return $subscription !== null && $subscription->source === 'stripe' && $subscription->stripe_subscription_id !== null && $subscription->isCurrent()
            ? $subscription
            : null;
    }

    /** After a change: forget what the page showed and bring the workspace's plan in line with Stripe now. */
    private function changed(Subscription $local): Subscription
    {
        $id = (string) $local->stripe_subscription_id;
        Cache::forget(self::cacheKey($id));

        return $this->subscriptions->sync($id, (bool) $local->tenant?->stripe_livemode) ?? $local;
    }
}
