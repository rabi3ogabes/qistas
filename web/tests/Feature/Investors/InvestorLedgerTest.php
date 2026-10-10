<?php

use App\Actions\CancelContract;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Domain\Investors\InvestorSummary;
use App\Domain\Investors\MainInvestor;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/*
 * Win Plan PP3: who funded each contract, and each investor's money as customers pay. Every payment credits its
 * principal and its profit to the contract's investor (profit = payment x markup / total), counted as money comes
 * in; a voided payment takes back exactly what it credited. The ledger is written whether or not the investors
 * screens are switched on, so the figures are right the day they are.
 */

beforeEach(fn () => switchOn(Feature::Investors));

/** A contract of 1,000 financed with a markup of 100, in ten instalments of 110. */
function fundedContract(Tenant $tenant, array $overrides = []): Contract
{
    return openContract($tenant, array_merge(['principal' => '1000.00', 'markup_type' => 'fixed', 'markup_value' => '100.00', 'installment_count' => 10], $overrides));
}

/** @return list<array{0: string, 1: string}> type and amount of each entry, oldest first */
function entriesFor(Tenant $tenant, array $where): array
{
    return asTenant($tenant, fn () => InvestorEntry::query()->where($where)->orderBy('created_at')->orderBy('id')->get()
        ->sortBy(fn (InvestorEntry $e) => [$e->created_at->getTimestamp(), array_search($e->type, ['funding_out', 'principal_back', 'profit_share', 'commission', 'funding_back'], true)])
        ->map(fn (InvestorEntry $e) => [$e->type, Money::add($e->amount, '0', 2)])->values()->all());
}

function investorPayment(Contract $contract, string $amount): Transaction
{
    return app(RecordPayment::class)->handle($contract, $amount, 'cash');
}

describe('the main investor', function () {
    it('is made once per workspace, as the business’s own capital in its currency', function () {
        $tenant = Tenant::factory()->create(['currency' => 'AED']);

        $first = MainInvestor::for($tenant);
        $again = MainInvestor::for($tenant);

        expect($again->id)->toBe($first->id)
            ->and($first->is_main)->toBeTrue()
            ->and($first->name)->toBe('Own capital')
            ->and($first->currency)->toBe('AED')
            ->and(asTenant($tenant, fn () => Investor::query()->count()))->toBe(1);
    });

    it('funds every contract nobody else funds, from the day it opens', function () {
        $tenant = Tenant::factory()->create();
        $contract = fundedContract($tenant, ['down_payment' => '200.00', 'principal' => '1200.00']);

        expect($contract->investor_id)->toBe(MainInvestor::for($tenant)->id)
            ->and(entriesFor($tenant, ['contract_id' => $contract->id]))->toBe([['funding_out', '-1000.00']]);
    });

    it('takes on contracts made before investors existed, with every payment they had', function () {
        $tenant = Tenant::factory()->create();
        $contract = fundedContract($tenant);
        investorPayment($contract, '110.00');
        // As the books looked before this feature: no investors, no entries, contracts funded by nobody.
        DB::table('investor_entries')->delete();
        DB::table('contracts')->update(['investor_id' => null]);
        DB::table('investors')->delete();

        $main = MainInvestor::for($tenant);

        expect(Contract::withoutGlobalScopes()->find($contract->id)->investor_id)->toBe($main->id)
            ->and(entriesFor($tenant, ['contract_id' => $contract->id]))->toBe([['funding_out', '-1000.00'], ['principal_back', '100.00'], ['profit_share', '10.00']]);
    });
});

describe('payments', function () {
    it('credits a payment of 110 on 1,000 + 100 as 100 of principal and 10 of profit', function () {
        $tenant = Tenant::factory()->create();
        $contract = fundedContract($tenant);

        $payment = investorPayment($contract, '110.00');

        expect(entriesFor($tenant, ['transaction_id' => $payment->id]))->toBe([['principal_back', '100.00'], ['profit_share', '10.00']]);
    });

    it('splits odd payments so each adds up to the payment, and the whole profit to the markup, to the cent', function () {
        $tenant = Tenant::factory()->create();
        $contract = fundedContract($tenant, ['markup_value' => '77.77']);
        $payments = ['33.33', '33.33', '0.01', '500.00', '511.10'];

        foreach ($payments as $amount) {
            $payment = investorPayment($contract, $amount);
            $sum = collect(entriesFor($tenant, ['transaction_id' => $payment->id]))->reduce(fn (string $c, array $e) => Money::add($c, $e[1], 2), '0.00');
            expect($sum)->toBe(Money::add($amount, '0', 2));
        }

        $profit = collect(entriesFor($tenant, ['contract_id' => $contract->id, 'type' => 'profit_share']))->reduce(fn (string $c, array $e) => Money::add($c, $e[1], 2), '0.00');
        $principal = collect(entriesFor($tenant, ['contract_id' => $contract->id, 'type' => 'principal_back']))->reduce(fn (string $c, array $e) => Money::add($c, $e[1], 2), '0.00');
        expect($profit)->toBe('77.77')->and($principal)->toBe('1000.00');
    });

    it('takes back exactly what a voided payment credited', function () {
        $tenant = Tenant::factory()->create();
        $contract = fundedContract($tenant);
        $payment = investorPayment($contract, '110.00');

        $reversal = app(VoidTransaction::class)->handle($payment);

        expect(entriesFor($tenant, ['transaction_id' => $reversal->id]))->toBe([['principal_back', '-100.00'], ['profit_share', '-10.00']])
            ->and(asTenant($tenant, fn () => InvestorEntry::query()->where('transaction_id', $reversal->id)->whereNull('reverses_entry_id')->count()))->toBe(0)
            ->and(InvestorSummary::for(MainInvestor::for($tenant))['wallet'])->toBe('-1000.00');
    });

    it('credits the contract’s own investor, and passes the agreed commission to the main investor', function () {
        [, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        $partner = asTenant($tenant, fn () => Investor::query()->forceCreate(['name' => 'Khalid', 'currency' => 'SAR', 'commission_percent' => '20']));
        $contract = fundedContract($tenant, ['investor_id' => $partner->id]);

        $payment = investorPayment($contract, '110.00');

        expect(entriesFor($tenant, ['transaction_id' => $payment->id, 'investor_id' => $partner->id]))->toBe([['principal_back', '100.00'], ['profit_share', '10.00'], ['commission', '-2.00']])
            ->and(entriesFor($tenant, ['transaction_id' => $payment->id, 'investor_id' => MainInvestor::for($tenant)->id]))->toBe([['commission', '2.00']]);
    });
});

describe('the entries', function () {
    it('can never be edited or deleted', function () {
        $tenant = Tenant::factory()->create();
        fundedContract($tenant);
        $entry = asTenant($tenant, fn () => InvestorEntry::query()->firstOrFail());

        expect(fn () => asTenant($tenant, fn () => $entry->forceFill(['amount' => '1'])->save()))->toThrow(LogicException::class)
            ->and(fn () => asTenant($tenant, fn () => $entry->delete()))->toThrow(LogicException::class);
    });

    it('gives a cancelled contract’s unpaid principal back to its investor', function () {
        $tenant = Tenant::factory()->create();
        $contract = fundedContract($tenant);
        investorPayment($contract, '110.00');

        app(CancelContract::class)->handle($contract);

        expect(entriesFor($tenant, ['contract_id' => $contract->id, 'type' => 'funding_back']))->toBe([['funding_back', '900.00']])
            ->and(InvestorSummary::for(MainInvestor::for($tenant))['out_in_contracts'])->toBe('0.00');
    });
});

describe('the summary', function () {
    it('shows the wallet, the money out, the profit earned and still to come, and the customers', function () {
        $tenant = Tenant::factory()->create();
        $main = MainInvestor::for($tenant);
        asTenant($tenant, fn () => InvestorEntry::query()->forceCreate([
            'investor_id' => $main->id, 'type' => 'deposit', 'amount' => '10000.00', 'occurred_on' => '2026-01-01',
        ]));
        $running = fundedContract($tenant);
        investorPayment($running, '550.00');
        $settled = fundedContract($tenant, ['principal' => '300.00', 'markup_value' => '30.00', 'installment_count' => 3]);
        investorPayment($settled, '330.00');
        $cancelled = fundedContract($tenant);
        app(CancelContract::class)->handle($cancelled);

        $summary = InvestorSummary::for($main);

        // 10,000 in, 2,300 out to contracts, 1,000 back with the cancelled one, 880 back from customers.
        expect($summary['wallet'])->toBe('9580.00')
            ->and($summary['out_in_contracts'])->toBe('500.00')
            ->and($summary['profit_earned'])->toBe('80.00')
            ->and($summary['profit_expected'])->toBe('50.00')
            ->and($summary['customers'])->toBe(2)
            ->and($summary['contracts'])->toBe(2);
    });
});
