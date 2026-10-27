<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\Notifier;
use App\Notifications\Push\PushMessage;
use App\Support\Format;
use App\Support\InLanguage;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;
use Carbon\CarbonInterface;

/**
 * What a workspace's owners are told about the plan they pay for (Sprint 3 Task 1b): seven days and one day before a
 * plan that does not renew by itself ends (a bank transfer, or a store subscription they cancelled), when the store
 * renews it or cannot take the payment, and when it has ended. Each notice goes once: its dedupe key names the
 * subscription and the end of the period it is about.
 */
final class BillingNotices
{
    /** Days before the end at which an owner is reminded; the larger also covers a plan bought with fewer days left. */
    public const AHEAD = [7, 1];

    /** How long after a plan has ended (grace included) the "it has ended" notice may still go. */
    private const ENDED_WITHIN_DAYS = 3;

    public function __construct(private readonly Notifier $notifier, private readonly CurrentTenant $current) {}

    /** The scheduled part: plans about to end without renewing, and plans that have ended. */
    public function run(): void
    {
        $free = Plan::default();
        $grace = (int) config('qistas.billing.grace_days');

        $subscriptions = Subscription::query()->with(['tenant', 'plan'])
            ->where('plan_id', '!=', $free->id)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', now()->subDays($grace + self::ENDED_WITHIN_DAYS))
            ->get();

        foreach ($subscriptions as $subscription) {
            $end = $subscription->current_period_end;
            if ($end === null || $subscription->tenant === null) {
                continue;
            }

            if ($end->isFuture() && in_array($subscription->status, Subscription::IN_FORCE, true) && $subscription->auto_renew !== true) {
                $days = (int) ceil(now()->diffInHours($end) / 24);
                $step = $days <= 1 ? 1 : ($days <= 7 ? 7 : null);
                if ($step !== null) {
                    $this->tell($subscription, 'billing.renewal_due', $end->toDateString().':'.$step, fn (Plan $plan) => [
                        __(':plan ends on :date', ['plan' => $plan->name, 'date' => $this->day($end)]),
                        __('Renew before :date to keep :plan. If it ends, the workspace goes back to Free and nothing is deleted.', ['plan' => $plan->name, 'date' => $this->day($end)]),
                    ]);
                }
            }

            if (! $subscription->isCurrent() && $end->copy()->addDays($grace)->isPast()) {
                $this->ended($subscription);
            }
        }
    }

    /** After the store told us about a subscription: [$outcome] as StoreSubscriptions names it. */
    public function storeEvent(Subscription $subscription, string $outcome): void
    {
        $end = $subscription->current_period_end;
        $period = $end?->toDateString() ?? 'none';

        match ($outcome) {
            'renewed' => $this->tell($subscription, 'billing.renewed', $period, fn (Plan $plan) => [
                __(':plan renewed', ['plan' => $plan->name]),
                __('Your workspace is on :plan until :date.', ['plan' => $plan->name, 'date' => $end === null ? '' : $this->day($end)]),
            ]),
            'grace', 'on_hold' => $this->tell($subscription, 'billing.payment_failed', $period, fn (Plan $plan) => [
                __('Payment not taken'),
                __('Google Play could not take the payment for :plan. Update your payment method in Google Play to keep it.', ['plan' => $plan->name]),
            ]),
            'expired', 'refunded' => $this->ended($subscription),
            default => null,
        };
    }

    /**
     * After Stripe told us about a card subscription (spec 2026-10-27 §7). [$outcome]: trial_ending, renewed,
     * payment_failed, action_needed, ended or refunded_duplicate; $context carries amount, currency, date, reference.
     *
     * @param  array{amount?: string, currency?: string, date?: CarbonInterface|null, reference?: string}  $context
     */
    public function card(Subscription $subscription, string $outcome, array $context = []): void
    {
        $end = $subscription->current_period_end;
        $period = $end?->toDateString() ?? 'none';
        $date = $context['date'] ?? $end;
        // Formatted inside each owner's language, like the rest of the words.
        $amount = fn (): string => isset($context['amount'], $context['currency']) ? Format::money($context['amount'], strtoupper($context['currency'])) : '';

        match ($outcome) {
            'trial_ending' => $this->tell($subscription, 'billing.trial_ending', $date?->toDateString() ?? $period, fn (Plan $plan) => [
                __('Your :plan trial ends soon', ['plan' => $plan->name]),
                __('Your :plan trial ends on :date. Your card will then be charged :amount; cancel any time before in Plan and billing.', [
                    'plan' => $plan->name, 'date' => $date === null ? '' : $this->day($date), 'amount' => $amount(),
                ]),
            ]),
            'renewed' => $this->tell($subscription, 'billing.renewed', $period, fn (Plan $plan) => [
                __(':plan renewed', ['plan' => $plan->name]),
                __('Your workspace is on :plan until :date.', ['plan' => $plan->name, 'date' => $end === null ? '' : $this->day($end)]),
            ]),
            'payment_failed' => $this->tell($subscription, 'billing.payment_failed', $period, fn (Plan $plan) => [
                __('Payment not taken'),
                __('We could not charge your card for :plan. Update it in Plan and billing; :plan stays on meanwhile.', ['plan' => $plan->name]),
            ]),
            'action_needed' => $this->tell($subscription, 'billing.action_needed', $period, fn (Plan $plan) => [
                __('Confirm your payment'),
                __('Your bank asks you to confirm the :plan payment. Open Plan and billing to confirm it.', ['plan' => $plan->name]),
            ]),
            'refunded_duplicate' => $this->tell($subscription, 'billing.refunded', $context['reference'] ?? $period, fn (Plan $plan) => [
                __('Second payment refunded'),
                __('Your workspace already had :plan, so the new card subscription was cancelled and its payment refunded.', ['plan' => $plan->name]),
            ]),
            'ended' => $this->ended($subscription),
            default => null,
        };
    }

    private function ended(Subscription $subscription): void
    {
        $this->tell($subscription, 'billing.expired', $subscription->current_period_end?->toDateString() ?? 'none', fn (Plan $plan) => [
            __(':plan has ended', ['plan' => $plan->name]),
            __('The workspace is on Free again. Nothing is deleted: renew any time to have everything back.'),
        ]);
    }

    /**
     * Tells each owner once: the key names the subscription, the kind of notice and [$period].
     *
     * @param  callable(Plan): array{0: string, 1: string}  $words  the title and the body, in the owner's language
     */
    private function tell(Subscription $subscription, string $type, string $period, callable $words): void
    {
        /** @var Tenant $tenant */
        $tenant = $subscription->tenant;
        $plan = $subscription->plan ?? Plan::default();

        $this->current->use($tenant, function () use ($tenant, $subscription, $type, $period, $words, $plan): void {
            $owners = $tenant->users()->wherePivot('role', TenantRole::Owner->value)->get();
            foreach ($owners as $owner) {
                /** @var User $owner */
                $key = $type.':'.$subscription->id.':'.$period.':'.$owner->id;
                if ($this->notifier->decided([$key]) !== []) {
                    continue;
                }

                $message = InLanguage::run((string) ($owner->locale ?: 'en'), function () use ($type, $words, $plan): PushMessage {
                    [$title, $body] = $words($plan);

                    return new PushMessage($type, $title, $body, ['route' => '/plans']);
                });
                $this->notifier->notify($owner, $message, [['type' => $type, 'subject_type' => 'subscription', 'subject_id' => $subscription->id, 'dedupe_key' => $key]]);
            }
        });
    }

    private function day(CarbonInterface $date): string
    {
        return $date->translatedFormat('j F Y');
    }
}
