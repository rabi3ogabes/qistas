<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;

/** @return array{0: User, 1: Customer} a member with this role, and a customer of their workspace, with the workspace active */
function memberWithRole(string $role): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $tenant->users()->attach($user->id, ['role' => $role]);
    $customer = asTenant($tenant, fn () => Customer::factory()->create());
    app(CurrentTenant::class)->set($tenant);

    return [$user, $customer];
}

it('lets every role look at customers', function (string $role) {
    [$user, $customer] = memberWithRole($role);

    expect(Gate::forUser($user)->allows('viewAny', Customer::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $customer))->toBeTrue();
})->with(['owner', 'manager', 'accountant', 'collector', 'viewer']);

it('lets everyone but viewers add and edit customers', function (string $role, bool $allowed) {
    [$user, $customer] = memberWithRole($role);

    expect(Gate::forUser($user)->allows('create', Customer::class))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('update', $customer))->toBe($allowed);
})->with([
    'owner' => ['owner', true],
    'manager' => ['manager', true],
    'accountant' => ['accountant', true],
    'collector' => ['collector', true],
    'viewer' => ['viewer', false],
]);

it('lets only owners and managers delete customers', function (string $role, bool $allowed) {
    [$user, $customer] = memberWithRole($role);

    expect(Gate::forUser($user)->allows('delete', $customer))->toBe($allowed);
})->with([
    'owner' => ['owner', true],
    'manager' => ['manager', true],
    'accountant' => ['accountant', false],
    'collector' => ['collector', false],
    'viewer' => ['viewer', false],
]);

it('refuses someone who does not belong to the active workspace, whatever their role elsewhere', function () {
    [, $customer] = memberWithRole('owner');
    $outsider = User::factory()->create();
    $elsewhere = Tenant::factory()->create();
    $elsewhere->users()->attach($outsider->id, ['role' => 'owner']);

    $gate = Gate::forUser($outsider);

    expect($gate->allows('view', $customer))->toBeFalse()
        ->and($gate->allows('create', Customer::class))->toBeFalse()
        ->and($gate->allows('update', $customer))->toBeFalse()
        ->and($gate->allows('delete', $customer))->toBeFalse();
});

it('refuses everything when no workspace is active', function () {
    [$user, $customer] = memberWithRole('owner');
    app(CurrentTenant::class)->clear();

    expect(Gate::forUser($user)->allows('viewAny', Customer::class))->toBeFalse();
});
