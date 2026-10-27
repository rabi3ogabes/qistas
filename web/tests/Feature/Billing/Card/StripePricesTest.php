<?php

use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Billing\Card\StripePrices;
use App\Models\Plan;
use App\Models\User;

/*
| Stripe prices come from the plan's own price in the admin area (spec 2026-10-27 D5): one Stripe price per plan,
| period, currency and amount, found again by its lookup key. Changing the price in the admin area makes a new Stripe
| price for new subscribers; nothing about a running subscription changes.
*/

beforeEach(function () {
    app(CardSettings::class)->saveKeys('sk_test_51Prices', 'pk_test_51Prices', User::factory()->create(['platform_role' => 'super_admin']));
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
});

function stripePricesPro(): Plan
{
    return Plan::where('key', 'pro')->sole();
}

it('names a price by plan, period, currency and amount', function () {
    expect(app(StripePrices::class)->lookupKey(stripePricesPro(), 'year'))->toBe('qistas_pro_year_usd_12000')
        ->and(app(StripePrices::class)->lookupKey(stripePricesPro(), 'month'))->toBe('qistas_pro_month_usd_1200');
});

it('finds or makes the Stripe price for a plan and period, from the plan\'s price', function () {
    $id = app(StripePrices::class)->for(stripePricesPro(), 'year');

    expect($this->stripe->price($id))->toBe([
        'plan' => 'pro', 'product' => 'Qistas Pro', 'minor' => 12000, 'currency' => 'USD', 'interval' => 'year', 'lookup_key' => 'qistas_pro_year_usd_12000',
    ]);
});

it('uses the same Stripe price while the plan\'s price stays the same', function () {
    $prices = app(StripePrices::class);

    expect($prices->for(stripePricesPro(), 'month'))->toBe($prices->for(stripePricesPro(), 'month'))
        ->and(array_unique(array_column($this->stripe->calls('priceFor'), 0)))->toBe(['qistas_pro_month_usd_1200']);
});

it('makes a new Stripe price after the price changes in the admin area', function () {
    $prices = app(StripePrices::class);
    $before = $prices->for(stripePricesPro(), 'year');

    stripePricesPro()->forceFill(['price_yearly' => '150'])->save();
    $after = $prices->for(stripePricesPro()->fresh(), 'year');

    expect($after)->not->toBe($before)
        ->and($this->stripe->price($after)['lookup_key'])->toBe('qistas_pro_year_usd_15000');
});

it('refuses a period the plan has no price for', function () {
    stripePricesPro()->forceFill(['price_monthly' => null])->save();

    expect(fn () => app(StripePrices::class)->for(stripePricesPro()->fresh(), 'month'))->toThrow(CardRefused::class);
    expect($this->stripe->calls('priceFor'))->toBe([]);
});

it('refuses the free plan and an unknown period', function (string $plan, string $period) {
    expect(fn () => app(StripePrices::class)->for(Plan::where('key', $plan)->sole(), $period))->toThrow(CardRefused::class);
})->with([['free', 'month'], ['pro', 'week']]);

it('refuses a price Stripe cannot charge exactly', function () {
    stripePricesPro()->forceFill(['currency' => 'KWD', 'price_yearly' => '12.345'])->save();

    expect(fn () => app(StripePrices::class)->for(stripePricesPro()->fresh(), 'year'))->toThrow(CardRefused::class);
});

it('lists the prices Stripe cannot charge, for the admin area', function () {
    stripePricesPro()->forceFill(['currency' => 'KWD', 'price_monthly' => '1.250', 'price_yearly' => '12.345'])->save();

    $problems = app(StripePrices::class)->problems();

    expect($problems)->toHaveCount(1)
        ->and($problems[0]['plan'])->toBe('Pro')
        ->and($problems[0]['period'])->toBe('year')
        ->and($problems[0]['problem'])->toContain('0.010');
});

it('has no problems with the built-in prices', function () {
    expect(app(StripePrices::class)->problems())->toBe([]);
});
