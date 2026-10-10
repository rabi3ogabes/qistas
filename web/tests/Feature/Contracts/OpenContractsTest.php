<?php

use App\Actions\ConvertToOpen;
use App\Actions\CreateContract;
use App\Actions\RecordCharge;
use App\Actions\RecordPayment;
use App\Domain\Investors\InvestorSummary;
use App\Domain\Investors\MainInvestor;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Reports\CustomerBalances;
use App\Reports\DashboardMetrics;
use Illuminate\Support\Facades\DB;

/*
 * Win Plan PP4: some customers keep a running tab. An open contract has no schedule: "they took" (a charge, عليه)
 * adds to the balance and "they paid" (له) takes from it, both ledger lines that are never edited, only reversed.
 * A scheduled or cash contract can become open: its unpaid instalments are superseded and what is left becomes one
 * opening line. Behind the open_contracts switch.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::OpenContracts);
});

function openAccount(Tenant $tenant, array $overrides = []): Contract
{
    return app(CreateContract::class)->handle($tenant, array_merge([
        'customer_id' => $overrides['customer_id'] ?? customerIn($tenant)->id, 'type' => 'open', 'start_date' => '2026-10-01',
    ], $overrides));
}

function tabCharge(Contract $contract, string $amount, array $options = []): Transaction
{
    return app(RecordCharge::class)->handle($contract, $amount, ...$options)['transaction'];
}

function balanceOf(Contract $contract): string
{
    return asTenant($contract->tenant, fn () => app(CustomerBalances::class)->forContracts([$contract->id])[$contract->id]);
}

describe('an open contract', function () {
    it('opens with no schedule, its opening balance as the first line and an optional credit limit', function () {
        [, $tenant] = apiOwner();

        $id = $this->postJson('/api/v1/contracts', [
            'customer_id' => customerIn($tenant)->id, 'type' => 'open', 'opening_balance' => '250.00', 'credit_limit' => '1000.00', 'start_date' => '2026-10-01',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'open')
            ->assertJsonPath('data.owed', '250.00')
            ->assertJsonPath('data.credit_limit', '1000.00')
            ->assertJsonPath('data.installment_count', 0)
            ->assertJsonPath('data.next_installment', null)
            ->json('data.id');

        expect(asTenant($tenant, fn () => Installment::query()->where('contract_id', $id)->count()))->toBe(0);
    });

    it('keeps the balance as what they took less what they paid, with the balance after every line in order', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);
        tabCharge($contract, '300.00');
        app(RecordPayment::class)->handle($contract, '120.00', 'cash');
        tabCharge($contract, '45.50', ['tag' => 'unpaid']);

        $lines = $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()
            ->assertJsonPath('data.owed', '225.50')->json('data.transactions');

        // Newest first, as every ledger reads; each line carries the balance once it was written.
        expect(collect($lines)->map(fn (array $l) => [$l['type'], $l['amount'], $l['balance_after']])->all())->toBe([
            ['charge', '45.50', '225.50'],
            ['payment', '120.00', '180.00'],
            ['charge', '300.00', '300.00'],
        ]);
    });

    it('takes a charge once however often the same request is sent', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);

        $first = $this->postJson("/api/v1/contracts/{$contract->id}/charges", ['amount' => '80.00', 'idempotency_key' => 'tab-1'])->assertCreated()->json('data.id');
        $again = $this->postJson("/api/v1/contracts/{$contract->id}/charges", ['amount' => '80.00', 'idempotency_key' => 'tab-1'])->assertSuccessful()->json('data.id');

        expect($again)->toBe($first)->and(balanceOf($contract))->toBe('80.00');
    });

    it('takes a payment on account without touching instalments, even ahead of what is owed', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);
        tabCharge($contract, '100.00');

        $payment = app(RecordPayment::class)->handle($contract, '150.00', 'cash', tag: 'advance');

        expect(asTenant($tenant, fn () => TransactionAllocation::query()->where('transaction_id', $payment->id)->count()))->toBe(0)
            ->and(balanceOf($contract))->toBe('-50.00')
            ->and($payment->tag)->toBe('advance')
            ->and(Contract::withoutGlobalScopes()->find($contract->id)->status)->toBe('active');
    });

    it('warns past the credit limit but never refuses the charge', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant, ['credit_limit' => '100.00']);

        $this->postJson("/api/v1/contracts/{$contract->id}/charges", ['amount' => '60.00'])->assertCreated()->assertJsonPath('meta.over_credit_limit', false);
        $this->postJson("/api/v1/contracts/{$contract->id}/charges", ['amount' => '60.00'])->assertCreated()->assertJsonPath('meta.over_credit_limit', true);

        expect(balanceOf($contract))->toBe('120.00');
    });

    it('stores the tag of a line and refuses one it does not know', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/charges", ['amount' => '10.00', 'tag' => 'refund'])->assertCreated()->assertJsonPath('data.tag', 'refund');
        $this->postJson("/api/v1/contracts/{$contract->id}/charges", ['amount' => '10.00', 'tag' => 'gift'])->assertStatus(422);
        $this->postJson("/api/v1/contracts/{$contract->id}/payments", ['amount' => '5.00', 'method' => 'cash', 'tag' => 'early_discount'])
            ->assertCreated()->assertJsonPath('data.tag', 'early_discount');
    });

    it('voids a charge the way a payment is voided, once', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);
        $line = tabCharge($contract, '70.00');

        $this->postJson("/api/v1/payments/{$line->id}/void", ['reason' => 'Wrong customer'])->assertOk()
            ->assertJsonPath('data.type', 'charge_reversal')->assertJsonPath('data.amount', '-70.00');
        $this->postJson("/api/v1/payments/{$line->id}/void")->assertStatus(422);

        expect(balanceOf($contract))->toBe('0.00');
    });

    it('never counts a charge as money collected, nor lists it among payments', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);
        tabCharge($contract, '500.00');
        app(RecordPayment::class)->handle($contract, '40.00', 'cash');

        expect(app(DashboardMetrics::class)->for($tenant)['collected_this_month'])->toBe('40.00');
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'payment');
    });

    it('counts open balances in the customer’s total and in what is still to collect', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);
        $contract = openAccount($tenant, ['customer_id' => $customer->id]);
        tabCharge($contract, '320.00');

        expect(asTenant($tenant, fn () => app(CustomerBalances::class)->forCustomers([$customer->id])[$customer->id]['owed']))->toBe('320.00')
            ->and(app(DashboardMetrics::class)->for($tenant)['outstanding'])->toBe('320.00');
    });

    it('takes no charge on a contract that is not open', function () {
        [, $tenant] = apiOwner();
        $scheduled = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$scheduled->id}/charges", ['amount' => '10.00'])->assertStatus(422);
    });
});

describe('becoming open', function () {
    it('shows exactly what will happen, then does just that', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant); // three instalments of 100
        app(RecordPayment::class)->handle($contract, '150.00', 'cash');

        $preview = $this->postJson("/api/v1/contracts/{$contract->id}/convert-to-open?preview=1")->assertOk()->json('data');
        expect(Contract::withoutGlobalScopes()->find($contract->id)->type)->toBe('scheduled');

        $done = $this->postJson("/api/v1/contracts/{$contract->id}/convert-to-open")->assertOk()->json('data');

        expect($preview)->toBe(['superseded' => 2, 'opening_balance' => '150.00'])
            ->and($done['conversion'])->toBe($preview)
            ->and($done['contract']['type'])->toBe('open')
            ->and($done['contract']['owed'])->toBe('150.00');
        expect(installmentsOfContract($contract)->pluck('status')->all())->toBe(['paid', 'superseded', 'superseded']);
    });

    it('stops counting superseded instalments as owed or late', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant); // due from 1 Feb 2026: all late by now
        app(ConvertToOpen::class)->handle($contract);

        expect(asTenant($tenant, fn () => Contract::query()->inView('late')->count()))->toBe(0)
            ->and(app(DashboardMetrics::class)->for($tenant)['overdue'])->toBe('0.00')
            ->and(app(DashboardMetrics::class)->for($tenant)['outstanding'])->toBe('300.00')
            ->and(balanceOf($contract))->toBe('300.00');
    });

    it('will not void a payment made before the contract became open', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        app(ConvertToOpen::class)->handle($contract);

        $this->postJson("/api/v1/payments/{$payment->id}/void")->assertStatus(422);
    });

    it('cannot turn a settled, cancelled or already open contract', function () {
        [, $tenant] = apiOwner();
        $open = openAccount($tenant);

        $this->postJson("/api/v1/contracts/{$open->id}/convert-to-open")->assertStatus(422);
    });

    it('is for owners and managers', function () {
        [, $tenant] = owner();
        $contract = openContract($tenant);
        apiMember('collector', $tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/convert-to-open")->assertForbidden();
    });
});

describe('with investors', function () {
    it('funds what the customer takes and credits what they pay, and a conversion funds nothing twice', function () {
        [, $tenant] = apiOwner();
        $contract = openAccount($tenant);
        tabCharge($contract, '400.00');
        app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        $converted = openContract($tenant);
        app(ConvertToOpen::class)->handle($converted);

        $summary = InvestorSummary::for(MainInvestor::for($tenant));

        // 400 + 300 out, 100 back.
        expect($summary['out_in_contracts'])->toBe('600.00');
    });

    it('brings older books up to date the same way, never funding a carried-over balance twice', function () {
        [, $tenant] = apiOwner();
        $converted = openContract($tenant);
        app(ConvertToOpen::class)->handle($converted);
        tabCharge($converted, '50.00');
        // As the books looked before investors: no investors, no entries, contracts funded by nobody.
        DB::table('investor_entries')->delete();
        DB::table('contracts')->update(['investor_id' => null]);
        DB::table('investors')->delete();

        expect(InvestorSummary::for(MainInvestor::for($tenant))['out_in_contracts'])->toBe('350.00');
    });
});

describe('with the switch off', function () {
    it('opens no open contract and converts none, but an open one still takes payments', function () {
        [, $tenant] = apiOwner();
        $open = openAccount($tenant);
        DB::table('platform_features')->where('feature_key', 'open_contracts')->update(['state' => 'off']);
        $scheduled = openContract($tenant);

        $this->postJson('/api/v1/contracts', ['customer_id' => customerIn($tenant)->id, 'type' => 'open', 'start_date' => '2026-10-01'])
            ->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
        $this->postJson("/api/v1/contracts/{$scheduled->id}/convert-to-open")->assertForbidden();
        $this->postJson("/api/v1/contracts/{$open->id}/charges", ['amount' => '10.00'])->assertForbidden();
        $this->postJson("/api/v1/contracts/{$open->id}/payments", ['amount' => '10.00', 'method' => 'cash'])->assertCreated();
    });
});

describe('on the web', function () {
    it('opens an open account from the form and shows its two ways in and out', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->get('/app/contracts/create')->assertOk()->assertSee('value="open"', false)->assertSee('Open account');

        $this->actingAs($user)->post('/app/contracts', ['customer_id' => $customer->id, 'type' => 'open', 'opening_balance' => '90', 'credit_limit' => '500', 'start_date' => '2026-10-01'])
            ->assertRedirect();
        $contract = asTenant($tenant, fn () => Contract::query()->sole());

        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('They took')->assertSee('They paid')->assertSee('Opening balance')->assertSee('90.00')
            ->assertSee('id="took-amount"', false)->assertSee('id="paid-amount"', false);
    });

    it('adds what they took, and warns once the balance passes the credit limit', function () {
        [$user, $tenant] = owner();
        $contract = openAccount($tenant, ['credit_limit' => '100.00']);

        $this->actingAs($user)->post(route('app.contracts.charges.store', $contract), ['amount' => '80'])
            ->assertRedirect(route('app.contracts.show', $contract))->assertSessionMissing('warning');
        $this->actingAs($user)->post(route('app.contracts.charges.store', $contract), ['amount' => '40', 'tag' => 'unpaid'])
            ->assertSessionHas('warning');

        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertSee('120.00')->assertSee('Unpaid');
    });

    it('shows what becoming open would do, then does it', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('Turn into an open contract')->assertSee('Unpaid instalments set aside, kept in the history: 3')->assertSee('300.00');

        $this->actingAs($user)->post(route('app.contracts.convert', $contract))->assertRedirect(route('app.contracts.show', $contract));
        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertSee('They took')->assertSee('The schedule before it became open');
    });

    it('keeps what they took out of the payments page', function () {
        [$user, $tenant] = owner();
        $contract = openAccount($tenant);
        tabCharge($contract, '444.00');

        $this->actingAs($user)->get('/app/payments')->assertOk()->assertDontSee('444.00');
    });
});
