<?php

namespace App\Billing\Card;

use App\Models\Plan;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Stripe prices made from the plan's own price in the admin area (spec 2026-10-27 D5): nothing is set up in Stripe by
 * hand. Each plan, period, currency and amount is one Stripe price, found again by its lookup key. A new price in the
 * admin area is a new lookup key, so new subscribers pay it while running subscriptions keep the price they joined at.
 */
final class StripePrices
{
    public const PERIODS = ['month', 'year'];

    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * The Stripe price for buying $plan by $period.
     *
     * @throws CardRefused when the plan is not for sale by that period, or Stripe could not charge its price exactly
     */
    public function for(Plan $plan, string $period): string
    {
        $price = $this->priceOf($plan, $period);
        if ($price === null) {
            throw new CardRefused(__('This plan cannot be bought by card at the moment.'));
        }

        try {
            $minor = StripeAmount::toMinor($price, $plan->currency);
        } catch (InvalidArgumentException) {
            throw new CardRefused(__('This plan cannot be bought by card at the moment.'));
        }

        return $this->stripe->priceFor($this->lookupKey($plan, $period), $plan->key, 'Qistas '.$plan->name, $minor, $plan->currency, $period);
    }

    /** qistas_pro_year_usd_12000: the plan, the period, the currency and the amount in Stripe's smallest unit. */
    public function lookupKey(Plan $plan, string $period): string
    {
        $price = (string) $this->priceOf($plan, $period);

        return implode('_', ['qistas', $plan->key, $period, strtolower($plan->currency), StripeAmount::toMinor($price, $plan->currency)]);
    }

    /**
     * The paid prices Stripe could not charge, for the admin area's check.
     *
     * @return list<array{plan: string, period: string, problem: string}>
     */
    public function problems(): array
    {
        $problems = [];
        foreach (Plan::query()->where('is_default', false)->orderBy('sort_order')->get() as $plan) {
            foreach (self::PERIODS as $period) {
                $price = $this->priceOf($plan, $period);
                $problem = $price === null ? null : StripeAmount::problem($price, $plan->currency);
                if ($problem !== null) {
                    $problems[] = ['plan' => $plan->name, 'period' => $period, 'problem' => $problem];
                }
            }
        }

        return $problems;
    }

    /** The plan's positive price for the period; null when it is not sold that way (free, unpriced, unknown period). */
    private function priceOf(Plan $plan, string $period): ?string
    {
        if ($plan->is_default || ! in_array($period, self::PERIODS, true)) {
            return null;
        }
        $price = $period === 'year' ? $plan->price_yearly : $plan->price_monthly;

        return $price !== null && Money::isPositive($price) ? $price : null;
    }
}
