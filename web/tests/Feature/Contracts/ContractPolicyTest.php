<?php

use App\Actions\CreateContract;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;

/** @return array{0: User, 1: Contract} a member with this role and a contract of their workspace, workspace active */
function contractMember(string $role): array
{
    $tenant = Tenant::factory()->create();
    $tenant->subscribeTo(Plan::default());
    $user = User::factory()->create();
    $tenant->users()->attach($user->id, ['role' => $role]);
    $contract = app(CreateContract::class)->handle($tenant, [
        'customer_id' => customerIn($tenant)->id, 'type' => 'cash', 'principal' => '100.00', 'start_date' => '2026-01-01',
    ]);
    app(CurrentTenant::class)->set($tenant);

    return [$user, $contract];
}

it('lets every role look at contracts', function (string $role) {
    [$user, $contract] = contractMember($role);

    expect(Gate::forUser($user)->allows('viewAny', Contract::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $contract))->toBeTrue();
})->with(['owner', 'manager', 'accountant', 'collector', 'viewer']);

it('lets everyone but viewers open a contract', function (string $role, bool $allowed) {
    [$user] = contractMember($role);

    expect(Gate::forUser($user)->allows('create', Contract::class))->toBe($allowed);
})->with([['owner', true], ['manager', true], ['accountant', true], ['collector', true], ['viewer', false]]);

it('lets only owners and managers cancel one', function (string $role, bool $allowed) {
    [$user, $contract] = contractMember($role);

    expect(Gate::forUser($user)->allows('cancel', $contract))->toBe($allowed);
})->with([['owner', true], ['manager', true], ['accountant', false], ['collector', false], ['viewer', false]]);

it('has no way to edit or delete a contract', function () {
    [$user, $contract] = contractMember('owner');

    expect(Gate::forUser($user)->allows('update', $contract))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $contract))->toBeFalse();
});

it('refuses someone outside the active workspace', function () {
    [, $contract] = contractMember('owner');
    $outsider = User::factory()->create();
    Tenant::factory()->create()->users()->attach($outsider->id, ['role' => 'owner']);

    expect(Gate::forUser($outsider)->allows('view', $contract))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('create', Contract::class))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('cancel', $contract))->toBeFalse();
});
