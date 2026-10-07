<?php

use App\Actions\RecordPayment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Gate;

/** @return array{0: User, 1: Transaction} */
function ledgerMember(string $role): array
{
    $tenant = workspaceOn();
    $user = User::factory()->create();
    $tenant->users()->attach($user->id, ['role' => $role]);
    $payment = app(RecordPayment::class)->handle(openContract($tenant), '10.00', 'cash');
    app(CurrentTenant::class)->set($tenant);

    return [$user, $payment];
}

it('lets every role look at the ledger', function (string $role) {
    [$user, $transaction] = ledgerMember($role);

    expect(Gate::forUser($user)->allows('viewAny', Transaction::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $transaction))->toBeTrue();
})->with(['owner', 'manager', 'accountant', 'collector', 'viewer']);

it('lets everyone but viewers record a payment', function (string $role, bool $allowed) {
    [$user] = ledgerMember($role);

    expect(Gate::forUser($user)->allows('create', Transaction::class))->toBe($allowed);
})->with([['owner', true], ['manager', true], ['accountant', true], ['collector', true], ['viewer', false]]);

it('lets only owners and managers void a payment', function (string $role, bool $allowed) {
    [$user, $transaction] = ledgerMember($role);

    expect(Gate::forUser($user)->allows('void', $transaction))->toBe($allowed);
})->with([['owner', true], ['manager', true], ['accountant', false], ['collector', false], ['viewer', false]]);

it('has no way to edit or delete a transaction', function () {
    [$user, $transaction] = ledgerMember('owner');

    expect(Gate::forUser($user)->allows('update', $transaction))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $transaction))->toBeFalse();
});

it('refuses someone outside the active workspace', function () {
    [, $transaction] = ledgerMember('owner');
    $outsider = User::factory()->create();
    Tenant::factory()->create()->users()->attach($outsider->id, ['role' => 'owner']);

    expect(Gate::forUser($outsider)->allows('view', $transaction))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('create', Transaction::class))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('void', $transaction))->toBeFalse();
});
