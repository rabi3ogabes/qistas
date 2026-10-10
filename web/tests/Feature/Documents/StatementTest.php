<?php

use App\Actions\CancelContract;
use App\Actions\CreateContract;
use App\Actions\RecordCharge;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Documents\DocumentOptions;
use App\Documents\Templates\ContractStatement;
use App\Documents\Templates\CustomerStatement;
use App\Domain\Ledger\Statement;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
| Win Plan PP8: a statement's numbers. Each contract opens with what the customer owes for it (the price less any
| discount, plus the markup) and every payment, void and charge moves a running balance from there, so the last line is
| always what the rest of the app says is still owed. A separate overdue column says what is late today.
*/

beforeEach(function () {
    $this->travelTo('2026-04-10 12:00:00');
});

/**
 * C-0001 for Ahmad Salem: 3 x 100.00 due 1 Feb, 1 Mar and 1 Apr 2026, and 150.00 paid on 5 Feb. On 10 Apr 150.00 is
 * still owed, all of it late (50.00 of the second instalment and the whole third).
 *
 * @return array{0: User, 1: Tenant, 2: Customer, 3: Contract}
 */
function seededStatement(): array
{
    [$user, $tenant] = owner();
    $customer = customerIn($tenant, ['name' => 'Ahmad Salem']);
    $contract = openContract($tenant, ['customer_id' => $customer->id]);
    app(RecordPayment::class)->handle($contract, '150.00', 'cash', by: $user, paidAt: Carbon::parse('2026-02-05 10:00:00'));

    return [$user, $tenant, $customer, $contract];
}

/** @return list<array{0: string, 1: string|null, 2: string|null, 3: string}> kind, debit, credit, balance */
function statementRows(Statement $statement): array
{
    return array_map(fn (array $line) => [$line['kind'], $line['debit'], $line['credit'], $line['balance']], $statement->lines);
}

function customerStatement(Tenant $tenant, Customer $customer, array $options = [], ?User $by = null): array
{
    return asTenant($tenant, fn () => (new CustomerStatement($customer))->data(DocumentOptions::from($options, $tenant, $by ?? $tenant->users()->first())));
}

function contractStatement(Tenant $tenant, Contract $contract, array $options = [], ?User $by = null): array
{
    return asTenant($tenant, fn () => (new ContractStatement($contract))->data(DocumentOptions::from($options, $tenant, $by ?? $tenant->users()->first())));
}

describe('the running balance', function () {
    it('starts at what was sold and goes down with every payment, to what is still owed', function () {
        [, $tenant, $customer] = seededStatement();

        $statement = customerStatement($tenant, $customer)['statement'];

        expect(statementRows($statement))->toBe([
            ['sale', '300.00', null, '300.00'],
            ['payment', null, '150.00', '150.00'],
        ])->and($statement->opening)->toBe('0.00')->and($statement->closing)->toBe('150.00');
    });

    it('counts the price less the discount, plus the markup, and the down payment as money in', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        switchOn(Feature::ContractItems);
        $contract = openContract($tenant, ['customer_id' => $customer->id, 'principal' => '1200.00', 'discount_type' => 'fixed', 'discount_value' => '200',
            'down_payment' => '100.00', 'markup_type' => 'fixed', 'markup_value' => '90', 'installment_count' => 3]);

        $statement = customerStatement($tenant, $customer)['statement'];

        // 1,200 - 200 + 90 = 1,090 owed for it; 100 down leaves the 990 the schedule collects.
        expect(statementRows($statement))->toBe([
            ['sale', '1090.00', null, '1090.00'],
            ['down_payment', null, '100.00', '990.00'],
        ])->and($statement->closing)->toBe('990.00')
            ->and(asTenant($tenant, fn () => Contract::find($contract->id))->total)->toBe('990.0000');
    });

    it('adds a voided payment back', function () {
        [$user, $tenant, $customer, $contract] = seededStatement();
        $payment = asTenant($tenant, fn () => $contract->transactions()->sole());
        app(VoidTransaction::class)->handle($payment, 'Entered twice', $user);

        expect(statementRows(customerStatement($tenant, $customer)['statement']))->toBe([
            ['sale', '300.00', null, '300.00'],
            ['payment', null, '150.00', '150.00'],
            ['reversal', '150.00', null, '300.00'],
        ]);
    });

    it('closes a cancelled contract at nothing owed', function () {
        [$user, $tenant, $customer, $contract] = seededStatement();
        app(CancelContract::class)->handle($contract, 'Returned the goods', $user);

        expect(statementRows(customerStatement($tenant, $customer)['statement']))->toBe([
            ['sale', '300.00', null, '300.00'],
            ['payment', null, '150.00', '150.00'],
            ['cancelled', null, '150.00', '0.00'],
        ]);
    });

    it('follows an open contract’s charges and payments', function () {
        switchOn(Feature::OpenContracts);
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        $tab = app(CreateContract::class)->handle($tenant, ['customer_id' => $customer->id, 'type' => 'open', 'start_date' => '2026-04-01'], $user);
        app(RecordCharge::class)->handle($tab, '80.00', by: $user);
        app(RecordPayment::class)->handle($tab, '30.00', 'cash', by: $user);

        expect(statementRows(customerStatement($tenant, $customer)['statement']))->toBe([
            ['charge', '80.00', null, '80.00'],
            ['payment', null, '30.00', '50.00'],
        ]);
    });

    it('opens a period with the balance before it, and keeps only that period’s lines', function () {
        [$user, $tenant, $customer, $contract] = seededStatement();
        app(RecordPayment::class)->handle($contract, '40.00', 'cash', by: $user, paidAt: Carbon::parse('2026-03-20 09:00:00'));

        $statement = customerStatement($tenant, $customer, ['from' => '2026-02-01', 'to' => '2026-02-28'])['statement'];

        expect($statement->opening)->toBe('300.00')
            ->and(statementRows($statement))->toBe([['payment', null, '150.00', '150.00']])
            ->and($statement->closing)->toBe('150.00');
    });

    it('keeps one contract of the customer when asked', function () {
        [$user, $tenant, $customer, $contract] = seededStatement();
        openContract($tenant, ['customer_id' => $customer->id, 'principal' => '600.00']);

        $data = customerStatement($tenant, $customer, ['contract' => $contract->id]);

        expect($data['statement']->closing)->toBe('150.00')->and($data['contracts'])->toHaveCount(1);
    });
});

describe('the overdue column', function () {
    it('shows what each instalment has late today, and the contract’s total late', function () {
        [, $tenant, , $contract] = seededStatement();

        $data = contractStatement($tenant, $contract);

        expect(array_map(fn (array $row) => [$row['number'], $row['amount'], $row['paid'], $row['overdue']], $data['schedule']))->toBe([
            [1, '100.00', '100.00', null],
            [2, '100.00', '50.00', '50.00'],
            [3, '100.00', '0.00', '100.00'],
        ])->and($data['overdue'])->toBe('150.00');
    });

    it('sums each contract’s late amount on the customer’s statement', function () {
        [, $tenant, $customer] = seededStatement();

        $contracts = customerStatement($tenant, $customer)['contracts'];

        expect($contracts[0]['owed'])->toBe('150.00')->and($contracts[0]['overdue'])->toBe('150.00');
    });

    it('is not late while still within the grace days', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        switchOn(Feature::FlexibleSchedules);
        $contract = openContract($tenant, ['customer_id' => $customer->id, 'first_due_date' => '2026-04-05', 'grace_days' => 7]);

        $data = contractStatement($tenant, $contract);

        expect($data['overdue'])->toBe('0.00')->and(array_column($data['schedule'], 'overdue'))->toBe([null, null, null]);
    });
});
