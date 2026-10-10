<?php

use App\Actions\CreateCustomer;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\LimitReached;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

// These tests are about how a limit behaves, so they set one; the free plan's real allowance is in FreePlanTest.
beforeEach(fn () => limitFreePlan(Feature::Customers, 5));

function newCustomerData(array $overrides = []): array
{
    return array_merge(['name' => 'Layla Haddad', 'phone' => '+966501234567'], $overrides);
}

function customersOf(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Customer::count());
}

it('creates a customer in the given workspace and records who did it', function () {
    $tenant = workspaceOn();
    $user = User::factory()->create();

    $customer = app(CreateCustomer::class)->handle($tenant, newCustomerData(['national_id' => '1098765432']), $user);

    expect($customer->tenant_id)->toBe($tenant->id)
        ->and($customer->created_by_user_id)->toBe($user->id)
        ->and($customer->name)->toBe('Layla Haddad')
        ->and($customer->national_id)->toBe('1098765432')
        ->and(customersOf($tenant))->toBe(1);
});

it('does not leave the workspace switched on after it finishes', function () {
    app(CreateCustomer::class)->handle(workspaceOn(), newCustomerData());

    expect(app(CurrentTenant::class)->get())->toBeNull();
});

it('lets a Free workspace add customers up to its limit, then refuses the next', function () {
    $tenant = workspaceOn('free');

    foreach (range(1, 5) as $i) {
        app(CreateCustomer::class)->handle($tenant, newCustomerData(['name' => "Customer {$i}"]));
    }

    expect(fn () => app(CreateCustomer::class)->handle($tenant, newCustomerData(['name' => 'Customer 6'])))
        ->toThrow(LimitReached::class);
    expect(customersOf($tenant))->toBe(5);
});

it('reports the limit and the usage when it refuses', function () {
    $tenant = workspaceOn('free');
    foreach (range(1, 5) as $_) {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
    }

    try {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
        $this->fail('Expected LimitReached');
    } catch (LimitReached $e) {
        expect($e->feature)->toBe(Feature::Customers)->and($e->limit)->toBe(5)->and($e->used)->toBe(5);
    }
});

it('never refuses a Pro workspace', function () {
    $tenant = workspaceOn('pro');

    foreach (range(1, 12) as $_) {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
    }

    expect(customersOf($tenant))->toBe(12);
});

it('counts each workspace separately', function () {
    $a = workspaceOn('free');
    $b = workspaceOn('free');
    foreach (range(1, 5) as $_) {
        app(CreateCustomer::class)->handle($a, newCustomerData());
    }

    app(CreateCustomer::class)->handle($b, newCustomerData());

    expect(customersOf($b))->toBe(1);
});

it('frees a place when a customer is deleted', function () {
    $tenant = workspaceOn('free');
    $created = collect(range(1, 5))->map(fn () => app(CreateCustomer::class)->handle($tenant, newCustomerData()));
    asTenant($tenant, fn () => $created->first()->delete());

    app(CreateCustomer::class)->handle($tenant, newCustomerData());

    expect(customersOf($tenant))->toBe(5);
});

it('reports real usage through the entitlement meter', function () {
    $tenant = workspaceOn('free');
    app(CreateCustomer::class)->handle($tenant, newCustomerData());
    app(CreateCustomer::class)->handle($tenant, newCustomerData());

    $customers = Entitlements::for($tenant)->check(Feature::Customers);

    expect($customers->used())->toBe(2)->and($customers->remaining())->toBe(3);
});

it('keeps every customer after a downgrade from Pro, and only blocks new ones', function () {
    $tenant = workspaceOn('pro');
    foreach (range(1, 7) as $_) {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
    }

    $tenant->subscribeTo(Plan::where('key', 'free')->sole());

    expect(customersOf($tenant))->toBe(7);
    expect(fn () => app(CreateCustomer::class)->handle($tenant, newCustomerData()))->toThrow(LimitReached::class);
    expect(customersOf($tenant))->toBe(7);
});

it('lets the admin raise the Free limit and takes effect on the next customer', function () {
    $tenant = workspaceOn('free');
    foreach (range(1, 5) as $_) {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
    }
    expect(fn () => app(CreateCustomer::class)->handle($tenant, newCustomerData()))->toThrow(LimitReached::class);

    Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 8);

    app(CreateCustomer::class)->handle($tenant, newCustomerData());
    expect(customersOf($tenant))->toBe(6);
});

it('restores the workspace context even when the limit stops it', function () {
    $tenant = workspaceOn('free');
    foreach (range(1, 5) as $_) {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
    }

    try {
        app(CreateCustomer::class)->handle($tenant, newCustomerData());
    } catch (LimitReached) {
    }

    expect(app(CurrentTenant::class)->get())->toBeNull();
});
