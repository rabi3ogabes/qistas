<?php

use App\Billing\Card\CardCustomers;
use App\Billing\Card\CardSettings;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Models\User;

/*
| One Stripe customer per workspace (spec 2026-10-27 §5), made at its first checkout and found again from Stripe's
| messages. A customer made with test keys is never used with live keys, and the other way round.
*/

beforeEach(function () {
    $this->admin = User::factory()->create(['platform_role' => 'super_admin']);
    app(CardSettings::class)->saveKeys('sk_test_51Customers', 'pk_test_51Customers', $this->admin);
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
});

it('creates the workspace\'s customer once, with its name, the owner\'s e-mail and language', function () {
    [$owner, $tenant] = owner(['name' => 'Al-Fares Electronics']);
    $owner->forceFill(['locale' => 'ar'])->save();
    $customers = app(CardCustomers::class);

    $first = $customers->for($tenant, $owner);
    $second = $customers->for($tenant->fresh(), $owner);

    expect($second)->toBe($first)
        ->and($this->stripe->calls('createCustomer'))->toHaveCount(1)
        ->and($this->stripe->customer($first))->toBe(['name' => 'Al-Fares Electronics', 'email' => $owner->email, 'locale' => 'ar', 'tenant_id' => $tenant->id])
        ->and($tenant->fresh()->stripe_customer_id)->toBe($first)
        ->and($tenant->fresh()->stripe_livemode)->toBeFalse();
});

it('asks Stripe with one key per workspace and mode, so two checkouts at once make one customer', function () {
    [$owner, $tenant] = owner();

    app(CardCustomers::class)->for($tenant, $owner);

    expect($this->stripe->calls('createCustomer')[0][1])->toBe(hash('sha256', 'customer|'.$tenant->id.'|test'));
});

it('makes a new customer when the platform moves from test keys to live keys', function () {
    [$owner, $tenant] = owner();
    $testCustomer = app(CardCustomers::class)->for($tenant, $owner);

    app(CardSettings::class)->saveKeys('sk_live_51Customers', 'pk_live_51Customers', $this->admin);
    $liveCustomer = app(CardCustomers::class)->for($tenant->fresh(), $owner);

    expect($liveCustomer)->not->toBe($testCustomer)
        ->and($tenant->fresh()->stripe_customer_id)->toBe($liveCustomer)
        ->and($tenant->fresh()->stripe_livemode)->toBeTrue();
});

it('finds the workspace from its customer, in the same mode only', function () {
    [$owner, $tenant] = owner();
    $customer = app(CardCustomers::class)->for($tenant, $owner);
    $customers = app(CardCustomers::class);

    expect($customers->tenantFor($customer, false)?->id)->toBe($tenant->id)
        ->and($customers->tenantFor($customer, true))->toBeNull()
        ->and($customers->tenantFor('cus_unknown', false))->toBeNull();
});
