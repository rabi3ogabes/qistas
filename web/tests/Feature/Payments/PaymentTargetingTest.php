<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\PaymentBatch;
use App\Models\Transaction;

/*
| Win Plan PP6: choosing which instalment a payment covers (it fills that one, then the oldest), "paid ahead until",
| and one payment across all of a customer's contracts, oldest due first or split by hand. Safe to retry.
|
| openContract() gives three monthly instalments of 100.00; these tests pick the dates.
*/

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::PaymentTargeting);
});

describe('choosing the instalment', function () {
    it('fills the chosen instalment first, then the oldest', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant, ['start_date' => '2026-10-01', 'first_due_date' => '2026-11-01']);
        $third = installmentsOfContract($contract)->firstWhere('number', 3);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '150.00', 'method' => 'cash', 'apply_to' => $third->id])->assertCreated();

        expect(installmentsOfContract($contract)->pluck('status')->all())->toBe(['partial', 'pending', 'paid']);
        expectConsistentLedger($contract);
    });

    it('refuses an instalment of another contract', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $other = installmentsOfContract(openContract($tenant))->first();

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '50.00', 'method' => 'cash', 'apply_to' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('apply_to', 'error.fields');
    });

    it('needs the feature to choose; a plain payment still works without it', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        app(FeatureControl::class)->setState(Feature::PaymentTargeting, PlatformState::Off, null, null);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '50.00', 'method' => 'cash', 'apply_to' => installmentsOfContract($contract)->last()->id])
            ->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '50.00', 'method' => 'cash'])->assertCreated();
    });

    it('says how far ahead a contract is paid', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant, ['start_date' => '2026-10-01', 'first_due_date' => '2026-11-01']);

        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.paid_ahead_until', null);
        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '200.00', 'method' => 'cash'])->assertCreated();

        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.paid_ahead_until', '2026-12-01');
    });
});

it('never says paid ahead for instalments already behind us', function () {
    [, $tenant] = apiOwner();
    // Due 1 Sep, 1 Oct and 1 Nov; today is 7 October.
    $contract = openContract($tenant, ['start_date' => '2026-08-01', 'first_due_date' => '2026-09-01']);

    $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '200.00', 'method' => 'cash'])->assertCreated();

    $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.paid_ahead_until', null);
});

describe('one payment across a customer’s contracts', function () {
    /** @return array{0: Customer, 1: Contract, 2: Contract} */
    function twoContracts($tenant): array
    {
        $customer = customerIn($tenant, ['name' => 'Amal']);
        $later = openContract($tenant, ['customer_id' => $customer->id, 'start_date' => '2026-10-01', 'first_due_date' => '2026-11-01']);
        $sooner = openContract($tenant, ['customer_id' => $customer->id, 'start_date' => '2026-09-15', 'first_due_date' => '2026-10-15']);

        return [$customer, $later, $sooner];
    }

    it('pays the oldest due first, across contracts, as one batch', function () {
        [$owner, $tenant] = apiOwner();
        [$customer, $later, $sooner] = twoContracts($tenant);

        $this->postJson("/api/v1/customers/{$customer->id}/payments", ['amount' => '250.00', 'method' => 'cash'])->assertCreated()
            ->assertJsonCount(2, 'data.payments');

        // 15 Oct (sooner #1) 100, 1 Nov (later #1) 100, 15 Nov (sooner #2) 50.
        expect(installmentsOfContract($sooner)->pluck('status')->all())->toBe(['paid', 'partial', 'pending'])
            ->and(installmentsOfContract($later)->pluck('status')->all())->toBe(['paid', 'pending', 'pending']);
        asTenant($tenant, function () use ($sooner, $later) {
            $batch = PaymentBatch::sole();
            expect($batch->amount)->toBe('250.0000')
                ->and(Transaction::where('batch_id', $batch->id)->pluck('amount', 'contract_id')->all())->toEqual([$sooner->id => '150.0000', $later->id => '100.0000']);
        });
        expectConsistentLedger($sooner);
        expectConsistentLedger($later);
    });

    it('is safe to retry with the same key', function () {
        [, $tenant] = apiOwner();
        [$customer] = twoContracts($tenant);
        $send = fn () => $this->postJson("/api/v1/customers/{$customer->id}/payments", ['amount' => '250.00', 'method' => 'cash'], ['Idempotency-Key' => 'batch-77']);

        $first = $send()->assertCreated();
        $second = $send()->assertOk();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and(asTenant($tenant, fn () => Transaction::count()))->toBe(2);
    });

    it('takes a split chosen by hand', function () {
        [, $tenant] = apiOwner();
        [$customer, $later, $sooner] = twoContracts($tenant);

        $this->postJson("/api/v1/customers/{$customer->id}/payments", [
            'amount' => '50.00', 'method' => 'cash',
            'split' => [['contract_id' => $later->id, 'amount' => '30.00'], ['contract_id' => $sooner->id, 'amount' => '20.00']],
        ])->assertCreated();

        expect(installmentsOfContract($later)->first()->paid_amount)->toBe('30.0000')
            ->and(installmentsOfContract($sooner)->first()->paid_amount)->toBe('20.0000');
    });

    it('is closed while the platform has it switched off', function () {
        [, $tenant] = apiOwner();
        [$customer] = twoContracts($tenant);
        app(FeatureControl::class)->setState(Feature::PaymentTargeting, PlatformState::Off, null, null);

        $this->postJson("/api/v1/customers/{$customer->id}/payments", ['amount' => '50.00', 'method' => 'cash'])->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });

    it('refuses more than is owed, a split that does not add up, and another customer’s contract', function () {
        [, $tenant] = apiOwner();
        [$customer, $later] = twoContracts($tenant);
        $stranger = openContract($tenant);

        $this->postJson("/api/v1/customers/{$customer->id}/payments", ['amount' => '601.00', 'method' => 'cash'])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$customer->id}/payments", ['amount' => '50.00', 'method' => 'cash', 'split' => [['contract_id' => $later->id, 'amount' => '40.00']]])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$customer->id}/payments", ['amount' => '50.00', 'method' => 'cash', 'split' => [['contract_id' => $stranger->id, 'amount' => '50.00']]])->assertStatus(422);

        expect(asTenant($tenant, fn () => Transaction::count()))->toBe(0);
    });
});
