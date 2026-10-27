<?php

namespace App\Billing\Card;

use App\Billing\BillingNotices;
use App\Billing\BillingState;
use App\Billing\Card\Data\CardEvent;
use App\Billing\Card\Data\CardSubscription;
use App\Billing\Trials;
use App\Models\Plan;
use App\Models\StoreEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Audit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Card subscriptions follow Stripe (spec 2026-10-27 §6, §7, §9). One rule: whatever the message, ask Stripe what is
 * true now and put the workspace there. The workspace is the one whose Stripe customer it is (never a name in the
 * message alone); each message is applied once; a second paid subscription is cancelled and refunded at once; the
 * owner hears about a trial ending, a renewal, a failed or held payment and the end.
 */
final class CardSubscriptions
{
    public const PROVIDER = 'stripe';

    /** What the webhook endpoint asks Stripe to send. */
    public const EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.trial_will_end',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
    ];

    /** Stripe statuses that keep the plan; the first payment still being made changes nothing. */
    private const IN_FORCE = ['trialing', 'active', 'past_due'];

    private const ENDED = ['incomplete_expired', 'canceled', 'unpaid', 'paused'];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly CardCustomers $customers,
        private readonly Trials $trials,
        private readonly BillingNotices $notices,
    ) {}

    /**
     * Apply one webhook message once (its signature is already checked).
     *
     * @return string what it did: trialing, active, past_due, incomplete, ended, refunded_duplicate, card_changed,
     *                trial_ending, renewed, paid, payment_failed, action_needed, ignored, unknown or duplicate
     *
     * @throws CardUnavailable when Stripe could not be asked; the message is left to be sent again
     */
    public function handle(CardEvent $event): string
    {
        $stored = StoreEvent::query()->where('provider', self::PROVIDER)->where('event_id', $event->id)->first();
        if ($stored?->processed_at !== null) {
            return 'duplicate';
        }
        if ($stored === null) {
            try {
                // Only what identifies the message: Stripe's objects carry e-mails and addresses Qistas does not keep.
                $stored = (new StoreEvent)->forceFill(['provider' => self::PROVIDER, 'event_id' => $event->id, 'payload' => ['type' => $event->type, 'object' => (string) ($event->object['id'] ?? '')]]);
                $stored->save();
            } catch (UniqueConstraintViolationException) {
                return 'duplicate'; // the same message arrived twice at once
            }
        }

        [$outcome, $subscription] = DB::transaction(fn (): array => $this->apply($event));
        $stored->forceFill(['outcome' => $outcome, 'processed_at' => now()])->save();
        if ($subscription !== null) {
            Audit::record('billing.card_event', $subscription, [
                'event' => $event->type, 'outcome' => $outcome, 'status' => $subscription->status, 'until' => $subscription->current_period_end?->toDateString(),
            ], $subscription->tenant_id);
        }

        return $outcome;
    }

    /** Put the workspace where Stripe says this subscription is now; null when it belongs to no workspace here. */
    public function sync(string $subscriptionId, bool $livemode): ?Subscription
    {
        return DB::transaction(fn (): ?Subscription => $this->follow($this->stripe->subscription($subscriptionId), $livemode)[1]);
    }

    /**
     * Back from a checkout: apply what it bought straight away, without waiting for the webhook.
     *
     * @return string complete, open or expired
     *
     * @throws ModelNotFoundException when the session belongs to another workspace's customer
     * @throws CardRefused when Stripe does not know the session
     * @throws CardUnavailable when Stripe could not be asked
     */
    public function syncSession(string $sessionId, Tenant $tenant): string
    {
        $session = $this->stripe->session($sessionId);
        if ($tenant->stripe_customer_id === null || $session->customerId !== $tenant->stripe_customer_id) {
            throw (new ModelNotFoundException)->setModel(Subscription::class);
        }

        if ($session->status === 'complete' && $session->subscriptionId !== null) {
            $subscriptionId = $session->subscriptionId;
            DB::transaction(fn () => $this->follow($this->stripe->subscription($subscriptionId), (bool) $tenant->stripe_livemode));
        }

        return in_array($session->status, ['complete', 'expired'], true) ? $session->status : 'open';
    }

    /** @return array{0: string, 1: Subscription|null} */
    private function apply(CardEvent $event): array
    {
        $object = $event->object;

        try {
            return match ($event->type) {
                'checkout.session.completed' => $this->checkoutCompleted($object, $event->livemode),
                'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->follow($this->stripe->subscription((string) ($object['id'] ?? '')), $event->livemode),
                'customer.subscription.trial_will_end' => $this->trialEnding((string) ($object['id'] ?? ''), $event->livemode),
                'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required' => $this->invoiceEvent($event->type, $object, $event->livemode),
                default => ['ignored', null],
            };
        } catch (CardRefused) {
            return ['unknown', null]; // Stripe no longer knows the object (deleted in the dashboard, a test clock ended)
        }
    }

    /**
     * The heart of it: the workspace follows what Stripe reports for $remote (§6), unless another paid plan is running.
     *
     * @return array{0: string, 1: Subscription|null}
     */
    private function follow(CardSubscription $remote, bool $livemode): array
    {
        $tenant = $this->customers->tenantFor($remote->customerId, $livemode);
        if ($tenant === null) {
            return ['unknown', null];
        }
        $named = $remote->metadata['tenant_id'] ?? null;
        if ($named !== null && $named !== $tenant->id) {
            Log::warning('stripe subscription names another workspace than its customer', ['subscription' => $remote->id]);

            return ['ignored', null];
        }

        $current = $tenant->subscription()->lockForUpdate()->first();
        $follows = $current !== null && $current->source === 'stripe' && $current->stripe_subscription_id === $remote->id;

        if (! $follows && $current !== null && $current->isCurrent() && in_array($current->source, BillingState::PAID_SOURCES, true)) {
            return $this->secondPayment($tenant, $current, $remote);
        }
        if ($remote->status === 'incomplete') {
            return ['incomplete', null];
        }
        if (in_array($remote->status, self::ENDED, true)) {
            if (! $follows) {
                return ['ignored', null];
            }
            $ended = $tenant->subscribeTo($current->plan ?? Plan::default(), 'expired', $current->current_period_end, ['auto_renew' => false] + $this->origin($remote));
            $this->notices->card($ended, 'ended');

            return ['ended', $ended];
        }
        if (! in_array($remote->status, self::IN_FORCE, true)) {
            return ['ignored', null];
        }

        $plan = Plan::query()->where('key', (string) ($remote->metadata['plan'] ?? ''))->where('is_default', false)->first() ?? ($follows ? $current->plan : null);
        if ($plan === null) {
            Log::warning('stripe subscription names no plan Qistas sells', ['subscription' => $remote->id]);

            return ['unknown', null];
        }

        $wasInForce = $follows && in_array($current->status, self::IN_FORCE, true);
        $until = $remote->status === 'trialing' ? ($remote->trialEnd ?? $remote->periodEnd) : $remote->periodEnd;
        $subscription = $tenant->subscribeTo($plan, $remote->status, $until, $this->origin($remote));
        $this->trials->markUsed($tenant);
        if (! $wasInForce) {
            Audit::record('billing.card_subscribed', $subscription, [
                'plan' => $plan->key, 'status' => $remote->status, 'period' => $subscription->period, 'until' => $until?->toDateString(),
            ], $tenant->id);
        }

        return [$remote->status, $subscription];
    }

    /**
     * Another paid plan already runs. A card subscription that is not paying yet changes nothing; an older one than the
     * card subscription the workspace follows is left alone; any other is cancelled now and its payment refunded.
     *
     * @return array{0: string, 1: Subscription|null}
     */
    private function secondPayment(Tenant $tenant, Subscription $current, CardSubscription $remote): array
    {
        if (! in_array($remote->status, self::IN_FORCE, true)) {
            return ['ignored', null];
        }
        if ($current->source === 'stripe' && $current->stripe_subscription_id !== null
            && $remote->created->lessThanOrEqualTo($this->stripe->subscription($current->stripe_subscription_id)->created)) {
            return ['ignored', null];
        }

        $this->stripe->cancelNow($remote->id, true);
        Audit::record('billing.card_refunded_duplicate', $current, ['subscription' => $remote->id, 'kept' => $current->source], $tenant->id);
        $this->notices->card($current, 'refunded_duplicate', ['reference' => $remote->id]);

        return ['refunded_duplicate', $current];
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{0: string, 1: Subscription|null}
     */
    private function checkoutCompleted(array $session, bool $livemode): array
    {
        if (($session['mode'] ?? null) === 'subscription' && is_string($session['subscription'] ?? null)) {
            return $this->follow($this->stripe->subscription($session['subscription']), $livemode);
        }
        if (($session['mode'] ?? null) !== 'setup' || ($session['metadata']['purpose'] ?? null) !== 'card_change') {
            return ['ignored', null];
        }

        // A new card saved on the billing page: it becomes the one the subscription is charged to.
        $tenant = is_string($session['customer'] ?? null) ? $this->customers->tenantFor($session['customer'], $livemode) : null;
        $current = $tenant?->subscription()->first();
        if ($current === null || $current->source !== 'stripe' || $current->stripe_subscription_id === null || ! is_string($session['setup_intent'] ?? null)) {
            return [$tenant === null ? 'unknown' : 'ignored', null];
        }
        $this->stripe->useSetupPaymentMethod($session['setup_intent'], $current->stripe_subscription_id);

        return ['card_changed', $current];
    }

    /** @return array{0: string, 1: Subscription|null} */
    private function trialEnding(string $subscriptionId, bool $livemode): array
    {
        $remote = $this->stripe->subscription($subscriptionId);
        [$outcome, $subscription] = $this->follow($remote, $livemode);
        if ($subscription === null || $outcome !== 'trialing') {
            return [$outcome, $subscription];
        }

        $next = $this->stripe->upcoming($subscriptionId);
        $this->notices->card($subscription, 'trial_ending', [
            'amount' => $next->amountDue ?? $remote->amount, 'currency' => $next->currency ?? $remote->currency, 'date' => $remote->trialEnd ?? $remote->periodEnd,
        ]);

        return ['trial_ending', $subscription];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{0: string, 1: Subscription|null}
     */
    private function invoiceEvent(string $type, array $invoice, bool $livemode): array
    {
        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? $invoice['subscription'] ?? null;
        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return ['ignored', null];
        }

        [$outcome, $subscription] = $this->follow($this->stripe->subscription($subscriptionId), $livemode);
        if ($subscription === null || ! in_array($outcome, self::IN_FORCE, true)) {
            return [$outcome, $subscription];
        }

        return match ($type) {
            'invoice.paid' => ($invoice['billing_reason'] ?? null) === 'subscription_cycle'
                ? $this->told($subscription, 'renewed')
                : ['paid', $subscription],
            'invoice.payment_failed' => $this->told($subscription, 'payment_failed'),
            default => $this->told($subscription, 'action_needed'),
        };
    }

    /** @return array{0: string, 1: Subscription} */
    private function told(Subscription $subscription, string $outcome): array
    {
        $this->notices->card($subscription, $outcome);

        return [$outcome, $subscription];
    }

    /** @return array{source: string, stripe_subscription_id: string, period: string, auto_renew: bool, store_status: string} */
    private function origin(CardSubscription $remote): array
    {
        return [
            'source' => 'stripe',
            'stripe_subscription_id' => $remote->id,
            'period' => $remote->interval === 'year' ? 'year' : 'month',
            'auto_renew' => ! $remote->cancelAtPeriodEnd,
            'store_status' => strtoupper($remote->status),
        ];
    }
}
