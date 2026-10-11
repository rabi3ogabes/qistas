<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\FeatureControlException;
use App\Entitlements\PlatformState;
use App\Models\Plan;
use App\Models\User;

/*
| Win Plan PP13: "Pro through Google Play" and "Pro by bank transfer" are the platform's own switches. They ship off,
| belong to no plan (a business cannot buy the way it pays), stay out of every price list, and Feature control shows
| them as a switch only.
*/

it('ship off, for the whole platform, outside every plan', function (Feature $feature) {
    expect($feature->scope())->toBe('platform')
        ->and($feature->launchState())->toBe(PlatformState::Off)
        ->and(Feature::planFeatures())->not->toContain($feature);
})->with([Feature::StoreBilling, Feature::ManualBilling]);

it('stay out of the price lists on the website, in the app and on the billing page', function () {
    $this->get(route('pricing'))->assertOk()->assertDontSee('Pro through Google Play')->assertDontSee('Pro by bank transfer');
    $this->getJson('/api/v1/plans')->assertOk()->assertDontSee('store_billing')->assertDontSee('manual_billing');

    [$owner] = owner();
    $this->actingAs($owner)->get(route('app.billing'))->assertOk()->assertDontSee('Pro through Google Play');
});

it('cannot be put into a plan', function () {
    expect(fn () => app(FeatureControl::class)->setPlan(Feature::StoreBilling, Plan::where('key', 'pro')->sole(), false, null, null))
        ->toThrow(FeatureControlException::class);
});

it('show as a switch only in Feature control', function () {
    $admin = User::factory()->create(['platform_role' => 'super_admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($admin)->get(route('admin.features.index'))->assertOk()
        ->assertSee('Pro through Google Play')->assertSee('A switch for the whole platform, not part of any plan');
});
