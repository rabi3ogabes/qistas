<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\Notifier;
use App\Notifications\Push\PushMessage;
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
