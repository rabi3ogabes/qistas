<?php

namespace App\Billing;

use App\Billing\Card\CardCheckout;
use App\Billing\Card\CardRefused;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;

/**
 * Where a workspace's plan stands and how it can buy one (Win Plan PP13), as the app and the website show it: the plan
 * in force, until when and where it came from, and, for the owner, what the store sells. The app reads only this, so
 * it never shows a way to pay that the platform has not switched on.
 */
final class BillingState
{
    /** The ways of paying for a plan; a plan the team gives (admin) is not one. */
    public const PAID_SOURCES = ['play', 'manual', 'stripe'];

    public function __construct(private readonly BillingSettings $settings) {}

    /** @return array<string, mixed> */
    public function for(Tenant $tenant, ?User $user): array
    {
        $plan = $tenant->currentPlan();
        $subscription = $tenant->subscription()->first();
        $current = $subscription?->isCurrent() && ! $plan->is_default ? $subscription : null;
        $canBuy = self::canBuy($tenant, $user);
        $store = $canBuy && $this->storeOpen($tenant);

        return [
            'plan' => ['key' => $plan->key, 'name' => $plan->name],
            'status' => $current?->status,
            'until' => $current?->current_period_end?->toDateString(),
            'source' => $current?->source,
            'auto_renew' => $current?->auto_renew,
            // month or year for a plan paid by period; never how to pay by card (the app does not sell it).
            'period' => $current?->period,
            'can_buy' => $canBuy,
            'store' => [
                'available' => $store,
                'account_ref' => $store ? StoreSubscriptions::accountRef($tenant) : null,
                'products' => $store ? $this->products() : [],
            ],
        ];
    }

    /** The owner of a real workspace; demo and test workspaces change plan through their own tools. */
    public static function canBuy(Tenant $tenant, ?User $user): bool
    {
        return $user !== null && ! $tenant->is_demo && ! $tenant->is_test && ($user->roleIn($tenant->id)?->managesBilling() ?? false);
    }

    /**
     * How the workspace pays for the plan it is on now: play, manual or stripe; null when it pays nothing (Free, or a
     * plan the team gave). A second way to pay is never offered while one is running (spec 2026-10-27 §9).
     */
    public static function paidThrough(Tenant $tenant): ?string
    {
        $subscription = $tenant->subscription()->first();

        return $subscription !== null && self::stillPaid($subscription) ? $subscription->source : null;
    }

    /**
     * A plan paid one way and still running on that payment. A plan that does not renew by itself (a bank transfer, a
     * renewal turned off) stops counting once its period is over, even during the grace days that keep it on: that is
     * when its owner is told to renew, so paying another way must be open then.
     */
    public static function stillPaid(Subscription $subscription): bool
    {
        if (! $subscription->isCurrent() || ! in_array($subscription->source, self::PAID_SOURCES, true)) {
            return false;
        }
        $end = $subscription->current_period_end;

        return $subscription->auto_renew === true || $end === null || $end->isFuture();
    }

    /** The store is switched on for this workspace and set up with something to sell, and no card subscription runs. */
    public function storeOpen(Tenant $tenant): bool
    {
        return Entitlements::for($tenant)->check(Feature::StoreBilling)->enabled() && $this->settings->playReady() && $this->products() !== []
            && self::paidThrough($tenant) !== 'stripe';
    }

    /** The owner may open a card checkout now (the website only; the app never offers it). */
    public function cardOpen(Tenant $tenant, ?User $user): bool
    {
        try {
            app(CardCheckout::class)->assertOpen($tenant, $user);
        } catch (CardRefused) {
            return false;
        }

        return true;
    }

    /** @return list<array{plan_key: string, product_id: string}> each paid plan on sale in the store */
    private function products(): array
    {
        $products = $this->settings->playProducts();
        $plans = Plan::query()->whereIn('key', array_keys($products))->where('is_default', false)->orderBy('sort_order')->pluck('key');

        return $plans->map(fn (string $key) => ['plan_key' => $key, 'product_id' => $products[$key]])->values()->all();
    }
}
