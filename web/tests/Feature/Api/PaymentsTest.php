<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\Transaction;

beforeEach(fn () => $this->travelTo('2026-10-07 12:00:00'));

function ledgerCount(Tenant $tenant, Contract $contract): int
{
    return asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count());
}

describe('taking a payment', function () {
    it('records it, applies it to the oldest instalments and says what changed', function () {
        [$user, $tenant] = apiOwner(['currency' => 'SAR']);
        $contract = openContract($tenant);

        $response = $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '140.00', 'method' => 'bank_transfer', 'note' => 'Transfer 8841'])
            ->assertCreated();

        $response->assertJsonPath('data.type', 'payment')
            ->assertJsonPath('data.method', 'bank_transfer')
            ->assertJsonPath('data.amount', '140.00')
            ->assertJsonPath('data.note', 'Transfer 8841')
            ->assertJsonPath('data.created_by.id', $user->id)
            ->assertJsonPath('data.contract.reference', $contract->reference())
            ->assertJsonPath('data.contract.owed', '160.00')
            ->assertJsonPath('data.contract.status', 'active');
        expect(array_column(installmentsOfContract($contract)->all(), 'status'))->toBe(['paid', 'partial', 'pending']);
        expectConsistentLedger($contract);
    });

    it('settles the contract with the last payment', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '300', 'method' => 'cash'])->assertCreated()->assertJsonPath('data.contract.status', 'settled');
    });

    it('is safe to retry: the same Idempotency-Key records one payment and answers the same', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $send = fn () => $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '100.00', 'method' => 'cash'], ['Idempotency-Key' => 'app-7f3a-9c21']);

        $first = $send()->assertCreated();
        $second = $send()->assertOk();

        expect($second->json('data.id'))->toBe($first->json('data.id'))->and(ledgerCount($tenant, $contract))->toBe(1);
    });

    it('will not let a key stand for a different payment', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '100.00', 'method' => 'cash'], ['Idempotency-Key' => 'reused'])->assertCreated();

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '50.00', 'method' => 'cash'], ['Idempotency-Key' => 'reused'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key', 'error.fields');
        expect(ledgerCount($tenant, $contract))->toBe(1);
    });

    it('rejects a malformed Idempotency-Key', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '10', 'method' => 'cash'], ['Idempotency-Key' => 'has spaces'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key', 'error.fields');
    });

    it('explains what is wrong, recording nothing', function (array $body, string $field) {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", $body)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors($field, 'error.fields');
        expect(ledgerCount($tenant, $contract))->toBe(0);
    })->with([
        'no amount' => [['method' => 'cash'], 'amount'],
        'zero' => [['amount' => '0', 'method' => 'cash'], 'amount'],
        'negative' => [['amount' => '-5', 'method' => 'cash'], 'amount'],
        'five decimals' => [['amount' => '1.00001', 'method' => 'cash'], 'amount'],
        'more than is owed' => [['amount' => '300.01', 'method' => 'cash'], 'amount'],
        'an unknown method' => [['amount' => '10', 'method' => 'bitcoin'], 'method'],
        'dated in the future' => [['amount' => '10', 'method' => 'cash', 'paid_at' => '2026-10-09'], 'paid_at'],
    ]);

    it('dates the payment on the day it was received', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '10', 'method' => 'cash', 'paid_at' => '2026-10-03'])->assertCreated();

        expect(asTenant($tenant, fn () => Transaction::sole()->paid_at->toDateString()))->toBe('2026-10-03');
    });

    it('refuses a cancelled contract', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        asTenant($tenant, fn () => Contract::find($contract->id)->forceFill(['status' => 'cancelled'])->save());

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '10', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('contract', 'error.fields');
    });

    it('is open to every role that may write, and closed to a viewer', function (string $role, int $status) {
        [, $tenant] = owner();
        $contract = openContract($tenant);
        apiMember($role, $tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '10', 'method' => 'cash'])->assertStatus($status);
    })->with([['owner', 201], ['manager', 201], ['accountant', 201], ['collector', 201], ['viewer', 403]]);

    it('needs a token', function () {
        $contract = openContract(Tenant::factory()->create());

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '10', 'method' => 'cash'])->assertUnauthorized();
    });
});

describe('the ledger', function () {
    it('lists every line of the workspace, newest first, and nobody else’s', function () {
        [$user, $tenant] = apiOwner(['currency' => 'SAR']);
        $first = openContract($tenant);
        $second = openContract($tenant);
        $this->travelTo('2026-10-08 12:00:00');
        app(RecordPayment::class)->handle($first, '40.00', 'cash', by: $user);
        $this->travelTo('2026-10-09 12:00:00');
        app(RecordPayment::class)->handle($second, '25.00', 'card', by: $user);
        app(RecordPayment::class)->handle(openContract(Tenant::factory()->create()), '77.00', 'cash');

        $response = $this->getJson('/api/v1/payments')->assertOk();

        expect(array_column($response->json('data'), 'amount'))->toBe(['25.00', '40.00'])
            ->and($response->json('data.0.contract.reference'))->toBe($second->reference())
            ->and($response->json('data.0.customer.name'))->toBe(asTenant($tenant, fn () => $second->customer->name))
            ->and($response->json('meta.total'))->toBe(2);
    });

    it('can be narrowed to one contract', function () {
        [, $tenant] = apiOwner();
        $first = openContract($tenant);
        $second = openContract($tenant);
        app(RecordPayment::class)->handle($first, '40.00', 'cash');
        app(RecordPayment::class)->handle($second, '25.00', 'cash');

        expect(array_column($this->getJson("/api/v1/payments?contract_id={$second->id}")->json('data'), 'amount'))->toBe(['25.00']);
    });

    it('needs a token', function () {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
    });
});

describe('voiding a payment', function () {
    it('reverses it, reopens what it paid and keeps the original', function () {
        [$user, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '150.00', 'cash');

        $this->postJson("/api/v1/payments/{$payment->id}/void", ['reason' => 'Wrong contract'])->assertOk()
            ->assertJsonPath('data.type', 'reversal')->assertJsonPath('data.amount', '-150.00')->assertJsonPath('data.note', 'Wrong contract')
            ->assertJsonPath('data.reverses_transaction_id', $payment->id)->assertJsonPath('data.contract.owed', '300.00');

        expect(ledgerCount($tenant, $contract))->toBe(2)->and(asTenant($tenant, fn () => Transaction::find($payment->id)->amount))->toBe('150.0000');
        expectConsistentLedger($contract);
    });

    it('shows a voided payment as voided in the contract', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        app(VoidTransaction::class)->handle(app(RecordPayment::class)->handle($contract, '100.00', 'cash'));

        $lines = $this->getJson("/api/v1/contracts/{$contract->id}")->json('data.transactions');

        expect(array_column($lines, 'voided'))->toBe([false, true])->and(array_column($lines, 'type'))->toBe(['reversal', 'payment']);
    });

    it('can be done once, and not to a down payment', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant, ['down_payment' => '50.00']);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        $down = asTenant($tenant, fn () => Transaction::where('type', 'down_payment')->sole());
        $this->postJson("/api/v1/payments/{$payment->id}/void")->assertOk();

        $this->postJson("/api/v1/payments/{$payment->id}/void")->assertUnprocessable()->assertJsonValidationErrors('transaction', 'error.fields');
        $this->postJson("/api/v1/payments/{$down->id}/void")->assertUnprocessable()->assertJsonValidationErrors('transaction', 'error.fields');
    });

    it('is only for owners and managers', function (string $role, int $status) {
        [, $tenant] = owner();
        $payment = app(RecordPayment::class)->handle(openContract($tenant), '100.00', 'cash');
        apiMember($role, $tenant);

        $this->postJson("/api/v1/payments/{$payment->id}/void")->assertStatus($status);
    })->with([['owner', 200], ['manager', 200], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('is a 404 for another workspace’s payment', function () {
        apiOwner();
        $payment = app(RecordPayment::class)->handle(openContract(Tenant::factory()->create()), '100.00', 'cash');

        $this->postJson("/api/v1/payments/{$payment->id}/void")->assertNotFound();
    });
});
