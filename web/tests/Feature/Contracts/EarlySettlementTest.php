<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Domain\Ledger\Statement;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\Contract;
use App\Models\ContractAdjustment;
use App\Models\InvestorEntry;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;

/*
| Win Plan PP6: early settlement (خصم سداد مبكر). The quote shows what is left and the rebate, a percent of the profit
| not yet earned or an amount, never more than that profit. Confirming settles the contract, waives what the rebate
| covers and writes one adjustment line. A quote that has gone stale is refused with the new figure.
|
| The contract: price 1,200.00, down payment 200.00, so 1,000.00 financed, 10% markup (100.00), 1,100.00 in four
| monthly instalments of 275.00 from 1 November. Profit counts as money comes in: paid x 100 / 1,100.
*/

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::EarlySettlement);
});

/** @return array{0: User, 1: Tenant, 2: Contract} */
function settleable(): array
{
    [$owner, $tenant] = apiOwner();
    $contract = openContract($tenant, [
        'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
        'installment_count' => 4, 'start_date' => '2026-10-01', 'first_due_date' => '2026-11-01',
    ]);

    return [$owner, $tenant, $contract];
}

function quoteFor(Contract $contract, array $query = []): array
{
    return test()->getJson("/api/v1/contracts/{$contract->id}/early-settlement?".http_build_query($query))->assertOk()->json('data');
}

function payOn(Contract $contract, string $amount): void
{
    app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), $amount, 'cash');
}

describe('the quote', function () {
    it('with nothing paid: everything left, all the profit still unearned', function () {
        [, , $contract] = settleable();

        expect(quoteFor($contract))->toMatchArray([
            'remaining' => '1100.00', 'earned_profit' => '0.00', 'unearned_profit' => '100.00', 'rebate' => '0.00', 'payoff' => '1100.00', 'capped' => false,
        ]);
    });

    it('after one instalment: a rebate as a percent of the profit not yet earned', function () {
        [, , $contract] = settleable();
        payOn($contract, '275.00');

        expect(quoteFor($contract, ['rebate_type' => 'percent', 'rebate_value' => '50']))->toMatchArray([
            'remaining' => '825.00', 'earned_profit' => '25.00', 'unearned_profit' => '75.00', 'rebate' => '37.50', 'payoff' => '787.50',
        ]);
    });

    it('caps a fixed rebate at the profit not yet earned, and says so', function () {
        [, , $contract] = settleable();
        payOn($contract, '275.00');

        expect(quoteFor($contract, ['rebate_type' => 'fixed', 'rebate_value' => '100']))->toMatchArray(['rebate' => '75.00', 'payoff' => '750.00', 'capped' => true]);
    });

    it('rounds the rebate half up to the cent', function () {
        [, , $contract] = settleable();
        payOn($contract, '275.00');

        // 75.00 x 33.333% = 24.99975
        expect(quoteFor($contract, ['rebate_type' => 'percent', 'rebate_value' => '33.333']))->toMatchArray(['rebate' => '25.00', 'payoff' => '800.00']);
    });

    it('counts a part payment’s profit to the cent', function () {
        [, , $contract] = settleable();
        payOn($contract, '100.00');

        // 100.00 x 100 / 1,100 = 9.0909...
        expect(quoteFor($contract))->toMatchArray(['remaining' => '1000.00', 'earned_profit' => '9.09', 'unearned_profit' => '90.91']);
    });

    it('refuses a rebate it cannot read', function () {
        [, , $contract] = settleable();

        $this->getJson("/api/v1/contracts/{$contract->id}/early-settlement?rebate_type=percent&rebate_value=150")->assertStatus(422);
        $this->getJson("/api/v1/contracts/{$contract->id}/early-settlement?rebate_type=gift&rebate_value=5")->assertStatus(422);
    });
});

describe('settling', function () {
    it('takes the payoff, waives what the rebate covers, writes one adjustment and settles the contract', function () {
        [$owner, $tenant, $contract] = settleable();
        payOn($contract, '275.00');

        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => '787.50', 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.status', 'settled')->assertJsonPath('data.owed', '0.00');

        $installments = installmentsOfContract($contract);
        expect($installments->pluck('status')->all())->toBe(['paid', 'paid', 'paid', 'waived'])
            ->and($installments->last()->paid_amount)->toBe('237.5000');
        // What the rebate covered is not owed: the waived instalment has nothing left on it.
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertJsonPath('data.installments.3.state', 'waived')->assertJsonPath('data.installments.3.remaining', '0.00');

        asTenant($tenant, function () use ($contract, $owner) {
            $adjustment = ContractAdjustment::sole();
            expect($adjustment->type)->toBe('early_rebate')->and($adjustment->amount)->toBe('37.5000')
                ->and($adjustment->created_by_user_id)->toBe($owner->id);
            $payment = Transaction::where('contract_id', $contract->id)->where('tag', 'early_discount')->sole();
            expect($payment->amount)->toBe('787.5000')->and($payment->tag)->toBe('early_discount')->and($adjustment->transaction_id)->toBe($payment->id);
        });
        expectConsistentLedger($contract);
    });

    it('leaves the statement at nothing owed, with the rebate as its own line', function () {
        [, $tenant, $contract] = settleable();
        payOn($contract, '275.00');
        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => '787.50', 'method' => 'cash'])->assertCreated();

        $statement = asTenant($tenant, fn () => Statement::of([Contract::find($contract->id)]));

        expect($statement->closing)->toBe('0.00')
            ->and(collect($statement->lines)->firstWhere('kind', 'early_rebate')['credit'])->toBe('37.50');
    });

    it('gives the investor every cent of principal back; the rebate comes out of profit only', function () {
        [, $tenant, $contract] = settleable();
        payOn($contract, '275.00');
        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => '787.50', 'method' => 'cash'])->assertCreated();

        asTenant($tenant, function () use ($contract) {
            expect(InvestorEntry::where('contract_id', $contract->id)->where('type', 'principal_back')->sum('amount'))->toEqual('1000.0000')
                ->and(InvestorEntry::where('contract_id', $contract->id)->where('type', 'profit_share')->sum('amount'))->toEqual('62.5000');
        });
    });

    it('refuses a quote that has gone stale, with the new figure', function () {
        [, , $contract] = settleable();
        payOn($contract, '275.00');
        $seen = quoteFor($contract, ['rebate_type' => 'percent', 'rebate_value' => '50']);
        payOn($contract, '100.00');

        // Now 725.00 left; 375.00 paid earns 34.09; half of the 65.91 still unearned is 32.955, so 32.96.
        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => $seen['payoff'], 'method' => 'cash'])
            ->assertStatus(409)->assertJsonPath('error.code', 'quote_changed')->assertJsonPath('data.payoff', '692.04');
        expect(Contract::withoutGlobalScopes()->find($contract->id)->status)->toBe('active');
    });

    it('is safe to retry with the same key, and settles only once', function () {
        [, $tenant, $contract] = settleable();
        $send = fn (array $headers = []) => $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['payoff' => '1100.00', 'method' => 'cash'], $headers);

        $send(['Idempotency-Key' => 'settle-k1'])->assertCreated();
        $send(['Idempotency-Key' => 'settle-k1'])->assertOk()->assertJsonPath('data.status', 'settled');
        $send()->assertStatus(422);

        expect(asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'payment')->count()))->toBe(1);
    });

    it('is for owners and managers only', function () {
        [, $tenant, $contract] = settleable();
        apiMember('collector', $tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['payoff' => '1100.00', 'method' => 'cash'])->assertForbidden();
        $this->getJson("/api/v1/contracts/{$contract->id}/early-settlement")->assertOk();
    });

    it('is closed while the platform has it switched off', function () {
        [, , $contract] = settleable();
        app(FeatureControl::class)->setState(Feature::EarlySettlement, PlatformState::Off, null, null);

        $this->getJson("/api/v1/contracts/{$contract->id}/early-settlement")->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });

    it('voiding the settlement brings the contract, the waived instalment and the rebate back', function () {
        [$owner, $tenant, $contract] = settleable();
        payOn($contract, '275.00');
        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => '787.50', 'method' => 'cash'])->assertCreated();
        $payment = asTenant($tenant, fn () => Transaction::where('tag', 'early_discount')->sole());

        app(VoidTransaction::class)->handle($payment, 'Wrong contract', $owner);

        expect(Contract::withoutGlobalScopes()->find($contract->id)->status)->toBe('active')
            ->and(installmentsOfContract($contract)->pluck('status')->all())->toBe(['paid', 'pending', 'pending', 'pending'])
            ->and(asTenant($tenant, fn () => Statement::of([Contract::find($contract->id)])->closing))->toBe('825.00')
            ->and(asTenant($tenant, fn () => InvestorEntry::where('contract_id', $contract->id)->where('type', 'principal_back')->sum('amount')))->toEqual('250.0000');
        expectConsistentLedger($contract);
    });

    it('voiding brings back an instalment the rebate waived whole, with nothing paid on it', function () {
        [$owner, $tenant] = apiOwner();
        // 1,000.00 at 50%: three instalments of 500.00. A 500.00 rebate leaves the payoff on the first two; the third is waived untouched.
        $contract = openContract($tenant, [
            'principal' => '1000.00', 'down_payment' => '0', 'markup_type' => 'percent', 'markup_value' => '50',
            'installment_count' => 3, 'start_date' => '2026-10-01', 'first_due_date' => '2026-11-01',
        ]);
        $this->postJson("/api/v1/contracts/{$contract->id}/early-settlement", ['rebate_type' => 'fixed', 'rebate_value' => '500', 'payoff' => '1000.00', 'method' => 'cash'])->assertCreated();
        expect(installmentsOfContract($contract)->pluck('status')->all())->toBe(['paid', 'paid', 'waived']);

        app(VoidTransaction::class)->handle(asTenant($tenant, fn () => Transaction::where('tag', 'early_discount')->sole()), 'Wrong customer', $owner);

        expect(installmentsOfContract($contract)->pluck('status')->all())->toBe(['pending', 'pending', 'pending']);
        expectConsistentLedger($contract);
    });
});
