<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Tenant;

beforeEach(fn () => $this->travelTo('2026-10-07 12:00:00'));

function contractBody(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => '200.00',
        'markup_type' => 'percent', 'markup_value' => '10', 'installment_count' => 4, 'frequency' => 'monthly',
        'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07', 'notes' => 'Washing machine',
    ], $overrides);
}

describe('listing', function () {
    it('needs a token', function () {
        $this->getJson('/api/v1/contracts')->assertUnauthorized();
    });

    it('lists running contracts first, newest first, with customer, state and what is owed', function () {
        [, $tenant] = apiOwner(['currency' => 'SAR']);
        $first = openContract($tenant, ['first_due_date' => '2026-11-01']);
        $late = openContract($tenant, ['first_due_date' => '2026-09-01']);
        openContract(Tenant::factory()->create());

        $response = $this->getJson('/api/v1/contracts')->assertOk();

        expect(array_column($response->json('data'), 'reference'))->toBe([$late->reference(), $first->reference()])
            ->and($response->json('data.0.state'))->toBe('late')->and($response->json('data.1.state'))->toBe('active')
            ->and($response->json('data.0.owed'))->toBe('300.00')
            ->and($response->json('data.0.total'))->toBe('300.00')
            ->and($response->json('data.0.customer.name'))->toBe(asTenant($tenant, fn () => $late->customer->name))
            ->and($response->json('data.1.next_installment'))->toBe(['number' => 1, 'due_date' => '2026-11-01', 'remaining' => '100.00']);
    });

    it('filters by status and searches', function () {
        [, $tenant] = apiOwner();
        $active = openContract($tenant, ['first_due_date' => '2026-11-01']);
        $late = openContract($tenant, ['first_due_date' => '2026-09-01']);
        $settled = openContract($tenant);
        app(RecordPayment::class)->handle($settled, '300.00', 'cash');

        $refs = fn (string $query) => array_column($this->getJson('/api/v1/contracts?'.$query)->assertOk()->json('data'), 'reference');

        expect($refs('status=all'))->toHaveCount(3)
            ->and($refs('status=late'))->toBe([$late->reference()])
            ->and($refs('status=settled'))->toBe([$settled->reference()])
            ->and($refs('status=active'))->toContain($active->reference(), $late->reference())->not->toContain($settled->reference())
            ->and($refs('status=all&q='.$active->reference()))->toBe([$active->reference()])
            ->and($refs('status=bogus'))->toHaveCount(2); // an unknown list means the default one
    });
});

describe('opening', function () {
    it('creates the contract, its instalments and the down payment', function () {
        [$user, $tenant] = apiOwner();
        $customer = customerIn($tenant);

        $response = $this->postJson('/api/v1/contracts', contractBody($customer))->assertCreated();

        $contract = asTenant($tenant, fn () => Contract::sole());
        $response->assertJsonPath('data.id', $contract->id)
            ->assertJsonPath('data.reference', 'C-0001')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.principal', '1200.00')
            ->assertJsonPath('data.down_payment', '200.00')
            ->assertJsonPath('data.financed', '1000.00')
            ->assertJsonPath('data.markup_amount', '100.00')
            ->assertJsonPath('data.total', '1100.00')
            ->assertJsonPath('data.installments.0.due_date', '2026-11-07')
            ->assertJsonPath('data.installments.0.amount', '275.00')
            ->assertJsonCount(4, 'data.installments');
        expect($contract->created_by_user_id)->toBe($user->id);
    });

    it('opens a cash sale with one instalment', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);

        $this->postJson('/api/v1/contracts', ['customer_id' => $customer->id, 'type' => 'cash', 'principal' => '450.00', 'start_date' => '2026-10-07'])
            ->assertCreated()->assertJsonPath('data.type', 'cash')->assertJsonCount(1, 'data.installments');
    });

    it('explains what is wrong', function (array $changes, string $field) {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);

        $this->postJson('/api/v1/contracts', contractBody($customer, $changes))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors($field, 'error.fields');
        expect(asTenant($tenant, fn () => Contract::count()))->toBe(0);
    })->with([
        'no price' => [['principal' => ''], 'principal'],
        'three decimals' => [['principal' => '10.005'], 'principal'],
        'down payment as large as the price' => [['down_payment' => '1200'], 'down_payment'],
        'no instalments' => [['installment_count' => 0], 'installment_count'],
        'too many instalments' => [['installment_count' => 121], 'installment_count'],
        'a due date before the contract' => [['first_due_date' => '2026-10-01'], 'first_due_date'],
        'an unknown frequency' => [['frequency' => 'daily'], 'frequency'],
    ]);

    it('will not take a customer of another workspace', function () {
        apiOwner();
        $foreign = customerIn(Tenant::factory()->create());

        $this->postJson('/api/v1/contracts', contractBody($foreign))->assertUnprocessable()->assertJsonValidationErrors('customer_id', 'error.fields');
    });

    it('stops at the plan limit with a 402', function () {
        limitFreePlan(Feature::ActiveContracts, 5);
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);
        foreach (range(1, 5) as $_) {
            openContract($tenant);
        }

        $this->postJson('/api/v1/contracts', contractBody($customer))->assertStatus(402)
            ->assertJsonPath('error.code', 'limit_reached')->assertJsonPath('error.feature', 'active_contracts')
            ->assertJsonPath('error.limit', 5)->assertJsonPath('error.used', 5)->assertJsonPath('error.upgrade_url', url('/app/billing'));
        expect(asTenant($tenant, fn () => Contract::count()))->toBe(5);
    });

    it('ignores fields a client should not set', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);

        $this->postJson('/api/v1/contracts', contractBody($customer, ['status' => 'settled', 'number' => 99, 'total' => '1.00', 'tenant_id' => 'x']))->assertCreated();

        $contract = asTenant($tenant, fn () => Contract::sole());
        expect($contract->status)->toBe('active')->and($contract->number)->toBe(1)->and($contract->total)->toBe('1100.0000');
    });

    it('is refused to a viewer', function () {
        [, $tenant] = owner();
        $customer = customerIn($tenant);
        apiMember('viewer', $tenant);

        $this->postJson('/api/v1/contracts', contractBody($customer))->assertForbidden();
    });
});

describe('the schedule preview', function () {
    it('shows the schedule that opening the contract would create, exactly', function () {
        apiOwner();

        $response = $this->postJson('/api/v1/contracts/preview', [
            'principal' => '100.00', 'down_payment' => '0', 'markup_type' => 'none', 'markup_value' => '0',
            'count' => 3, 'frequency' => 'monthly', 'first_due_date' => '2026-11-01',
        ])->assertOk();

        expect(array_column($response->json('data.installments'), 'amount'))->toBe(['33.33', '33.33', '33.34'])
            ->and($response->json('data.total'))->toBe('100.00');
    });

    it('matches the contract that is then opened', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);
        $body = contractBody($customer);

        $preview = $this->postJson('/api/v1/contracts/preview', [
            'principal' => $body['principal'], 'down_payment' => $body['down_payment'], 'markup_type' => $body['markup_type'],
            'markup_value' => $body['markup_value'], 'count' => $body['installment_count'], 'frequency' => $body['frequency'],
            'first_due_date' => $body['first_due_date'],
        ])->json('data.installments');
        $this->postJson('/api/v1/contracts', $body)->assertCreated();

        $opened = asTenant($tenant, fn () => Installment::orderBy('number')->get()->map(fn ($i) => ['number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => number_format((float) $i->amount, 2, '.', '')])->all());
        expect($preview)->toBe($opened);
    });

    it('refuses impossible plans, and needs a token', function () {
        $this->postJson('/api/v1/contracts/preview', ['principal' => '100'])->assertUnauthorized();

        apiOwner();
        $this->postJson('/api/v1/contracts/preview', ['principal' => '100', 'down_payment' => '100', 'count' => 3, 'frequency' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('down_payment', 'error.fields');
    });
});

describe('one contract', function () {
    it('is shown with its schedule and the money received', function () {
        [$user, $tenant] = apiOwner(['currency' => 'SAR']);
        $contract = openContract($tenant, ['first_due_date' => '2026-10-05']);
        app(RecordPayment::class)->handle($contract, '150.00', 'bank_transfer', by: $user, note: 'Transfer 8841');

        $response = $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk();

        expect(array_column($response->json('data.installments'), 'state'))->toBe(['paid', 'partial', 'upcoming'])
            ->and($response->json('data.installments.1'))->toMatchArray(['number' => 2, 'amount' => '100.00', 'paid_amount' => '50.00', 'remaining' => '50.00', 'due_date' => '2026-11-05'])
            ->and($response->json('data.owed'))->toBe('150.00')
            ->and($response->json('data.paid'))->toBe('150.00')
            ->and($response->json('data.transactions.0'))->toMatchArray(['type' => 'payment', 'method' => 'bank_transfer', 'amount' => '150.00', 'note' => 'Transfer 8841', 'voided' => false])
            ->and($response->json('data.transactions.0.created_by.name'))->toBe($user->name);
    });

    it('marks an unpaid instalment past its date as overdue', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant, ['first_due_date' => '2026-09-01']);

        $states = array_column($this->getJson("/api/v1/contracts/{$contract->id}")->json('data.installments'), 'state');

        expect($states)->toBe(['overdue', 'overdue', 'upcoming']);
    });

    it('is a 404 when it belongs to another workspace', function () {
        apiOwner();
        $theirs = openContract(Tenant::factory()->create());

        $this->getJson("/api/v1/contracts/{$theirs->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->postJson("/api/v1/contracts/{$theirs->id}/cancel")->assertNotFound();
        $this->postJson("/api/v1/contracts/{$theirs->id}/payments", ['amount' => '10', 'method' => 'cash'])->assertNotFound();
    });
});

describe('cancelling', function () {
    it('cancels a running contract, frees its place and writes an audit entry', function () {
        [$user, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/cancel", ['reason' => 'Returned'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.owed', '0.00');

        expect(asTenant($tenant, fn () => Contract::find($contract->id)->status))->toBe('cancelled')
            ->and(AuditLog::where('action', 'contract.cancelled')->sole()->user_id)->toBe($user->id);
    });

    it('is only for owners and managers', function (string $role, int $status) {
        [, $tenant] = owner();
        $contract = openContract($tenant);
        apiMember($role, $tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/cancel")->assertStatus($status);
    })->with([['owner', 200], ['manager', 200], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('cannot be done twice', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $this->postJson("/api/v1/contracts/{$contract->id}/cancel")->assertOk();

        $this->postJson("/api/v1/contracts/{$contract->id}/cancel")->assertUnprocessable()->assertJsonValidationErrors('contract', 'error.fields');
    });
});
