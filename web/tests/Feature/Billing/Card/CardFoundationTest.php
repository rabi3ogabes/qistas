<?php

use App\Actions\Admin\ChangePlan;
use App\Billing\BillingSettings;
use App\Billing\ManualPayments;
use App\Billing\Store\FakeStoreBilling;
use App\Billing\Store\StoreBilling;
use App\Billing\Store\StorePurchase;
use App\Billing\StoreSubscriptions;
use App\Billing\Trials;
use App\Entitlements\Feature;
use App\Entitlements\PlatformState;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

/*
| Pro by card, groundwork (spec 2026-10-27 §5.1, §6): the columns, the platform switch, and the rule that a workspace
| gets one free trial in its life, whichever way it first pays. A plan the team gives never counts as that trial.
*/

beforeEach(fn () => $this->travelTo('2026-10-27 09:00:00'));

/** A Google Play purchase of Pro for the workspace, verified the way the app does it. */
function cardFoundationPlayPurchase(Tenant $tenant, string $token): void
{
    switchOn(Feature::StoreBilling);
    $settings = app(BillingSettings::class);
    $settings->set(BillingSettings::PLAY_PACKAGE, 'app.qistas');
    $settings->set(BillingSettings::PLAY_PRODUCTS, (string) json_encode(['pro' => 'qistas_pro']));
    $store = app()->bound(StoreBilling::class) && app(StoreBilling::class) instanceof FakeStoreBilling ? app(StoreBilling::class) : new FakeStoreBilling;
    app()->instance(StoreBilling::class, $store);
    $store->record($token, new StorePurchase(
        productId: 'qistas_pro', state: 'active', expiresAt: CarbonImmutable::parse('2026-11-27 09:00:00'), autoRenew: true,
        acknowledged: true, accountRef: StoreSubscriptions::accountRef($tenant),
    ));

    app(StoreSubscriptions::class)->verify($tenant, 'qistas_pro', $token);
}

/** A bank transfer for Pro, sent by the owner and approved by the team. */
function cardFoundationTransfer(Tenant $tenant, User $owner, string $key): void
{
    switchOn(Feature::ManualBilling);
    app(BillingSettings::class)->set(BillingSettings::BANK_DETAILS, 'Qistas Ltd');
    $payments = app(ManualPayments::class);
    $request = $payments->submit($tenant, 'pro', 'year', 'bank_transfer', 'TRX-'.$key, UploadedFile::fake()->image('receipt.jpg', 400, 600), $owner, $key);

    $payments->approve($request, User::factory()->create(['platform_role' => 'super_admin']));
}

it('adds the card columns', function () {
    expect(Schema::hasColumns('tenants', ['stripe_customer_id', 'stripe_livemode', 'trial_used_at']))->toBeTrue()
        ->and(Schema::hasColumns('subscriptions', ['stripe_subscription_id', 'period']))->toBeTrue();
});

it('lists Pro by card as a platform switch that ships off', function () {
    expect(Feature::CardBilling->value)->toBe('card_billing')
        ->and(Feature::CardBilling->scope())->toBe('platform')
        ->and(Feature::CardBilling->label())->toBe('Pro by card')
        ->and(Feature::CardBilling->launchState())->toBe(PlatformState::Off)
        ->and(Feature::planFeatures())->not->toContain(Feature::CardBilling);
});

it('keeps the card subscription and its period on the workspace subscription', function () {
    [, $tenant] = owner();

    $subscription = $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addYear(), [
        'source' => 'stripe', 'stripe_subscription_id' => 'sub_123', 'period' => 'year', 'auto_renew' => true,
    ]);

    expect($subscription->fresh()->stripe_subscription_id)->toBe('sub_123')
        ->and($subscription->fresh()->period)->toBe('year')
        ->and($subscription->fresh()->source)->toBe('stripe');
});

it('clears the card fields when another source takes over', function () {
    [, $tenant] = owner();
    $pro = Plan::where('key', 'pro')->sole();
    $tenant->subscribeTo($pro, 'active', now()->addYear(), ['source' => 'stripe', 'stripe_subscription_id' => 'sub_123', 'period' => 'year']);

    $subscription = $tenant->subscribeTo($pro, 'active', now()->addMonth(), ['source' => 'admin']);

    expect($subscription->fresh()->stripe_subscription_id)->toBeNull()
        ->and($subscription->fresh()->period)->toBeNull();
});

it('marks the trial used the first time a purchase turns a plan on, and keeps that first date', function (string $how) {
    [$owner, $tenant] = owner();
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    expect(app(Trials::class)->available($tenant))->toBeTrue();

    $how === 'play' ? cardFoundationPlayPurchase($tenant, 'tok-1') : cardFoundationTransfer($tenant, $owner, 'first-key-1');
    $first = $tenant->fresh()->trial_used_at;

    $this->travel(3)->days();
    $how === 'play' ? cardFoundationPlayPurchase($tenant->fresh(), 'tok-2') : cardFoundationTransfer($tenant->fresh(), $owner, 'second-key-2');

    expect($first)->not->toBeNull()
        ->and($first->toDateTimeString())->toBe('2026-10-27 09:00:00')
        ->and($tenant->fresh()->trial_used_at->toDateTimeString())->toBe('2026-10-27 09:00:00')
        ->and(app(Trials::class)->available($tenant->fresh()))->toBeFalse();
})->with(['play', 'manual']);

it('writes the period of a bank transfer', function () {
    [$owner, $tenant] = owner();
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    cardFoundationTransfer($tenant, $owner, 'period-key-1');

    expect($tenant->fresh()->subscription()->first()->period)->toBe('year');
});

it('does not count a plan given by the team as a trial', function () {
    [, $tenant] = owner();

    app(ChangePlan::class)->handle($tenant, Plan::where('key', 'pro')->sole(), now()->addMonth(), 'Launch gift', User::factory()->create(['platform_role' => 'super_admin']));

    expect($tenant->fresh()->trial_used_at)->toBeNull()
        ->and(app(Trials::class)->available($tenant->fresh()))->toBeTrue();
});

it('never moves the date once the trial is used', function () {
    [, $tenant] = owner();
    $trials = app(Trials::class);

    $trials->markUsed($tenant);
    $this->travel(10)->days();
    $trials->markUsed($tenant->fresh());

    expect($tenant->fresh()->trial_used_at->toDateTimeString())->toBe('2026-10-27 09:00:00');
});
