<?php

use App\Actions\CreateContract;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\LimitReached;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/** @return array<string, mixed> a valid scheduled contract: 1,000 financed over 4 monthly instalments plus 10 % */
function contractData(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id,
        'type' => 'scheduled',
        'principal' => '1200.00',
        'down_payment' => '200.00',
        'markup_type' => 'percent',
        'markup_value' => '10',
        'installment_count' => 4,
        'frequency' => 'monthly',
        'start_date' => '2026-01-15',
        'first_due_date' => '2026-02-15',
    ], $overrides);
}

function contractsOf(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Contract::count());
}

function installmentsOf(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Installment::count());
}

/** Open as many contracts as the workspace has room for, for the limit tests. */
function fillContracts(Tenant $tenant, Customer $customer, int $count): void
{
    foreach (range(1, $count) as $_) {
        app(CreateContract::class)->handle($tenant, contractData($customer));
    }
}

it('stores the contract and exactly the instalments the schedule generator produces', function () {
    $tenant = workspaceOn();
    $customer = customerIn($tenant);
    $data = contractData($customer);

    $contract = app(CreateContract::class)->handle($tenant, $data);

    $expected = (new ScheduleGenerator)->generate(ScheduleRequest::fromArray([
        'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
        'count' => 4, 'frequency' => 'monthly', 'first_due_date' => '2026-02-15',
    ]));
    $stored = asTenant($tenant, fn () => $contract->installments()->orderBy('number')->get());

    expect($stored)->toHaveCount(4);
    foreach ($expected->installments as $i => $row) {
        expect($stored[$i]->number)->toBe($row['number'])
            ->and($stored[$i]->due_date->format('Y-m-d'))->toBe($row['due_date'])
            ->and(Money::cmp($stored[$i]->amount, $row['amount']))->toBe(0);
    }
    expect(Money::cmp($contract->financed, '1000.00'))->toBe(0)
        ->and(Money::cmp($contract->markup_amount, '100.00'))->toBe(0)
        ->and(Money::cmp($contract->total, '1100.00'))->toBe(0)
        ->and(Money::cmp($contract->principal, '1200.00'))->toBe(0)
        ->and(Money::cmp($contract->down_payment, '200.00'))->toBe(0);
});

it('makes the instalments add up exactly to the total, even when it does not divide evenly', function () {
    $tenant = workspaceOn();
    $customer = customerIn($tenant);

    $contract = app(CreateContract::class)->handle($tenant, contractData($customer, [
        'principal' => '100.00', 'down_payment' => '0', 'markup_type' => 'none', 'markup_value' => '0', 'installment_count' => 3,
    ]));

    $amounts = asTenant($tenant, fn () => $contract->installments()->orderBy('number')->pluck('amount')->all());
    $sum = array_reduce($amounts, fn (string $carry, string $amount) => Money::add($carry, $amount), '0');
    expect(Money::cmp($sum, '100.00'))->toBe(0)
        ->and(Money::cmp($amounts[0], '33.33'))->toBe(0)
        ->and(Money::cmp($amounts[2], '33.34'))->toBe(0);
});

it('lands month-end instalments on the last day of shorter months', function () {
    $tenant = workspaceOn();
    $customer = customerIn($tenant);

    $contract = app(CreateContract::class)->handle($tenant, contractData($customer, [
        'principal' => '300.00', 'down_payment' => '0', 'markup_type' => 'none', 'markup_value' => '0',
        'installment_count' => 3, 'first_due_date' => '2026-01-31',
    ]));

    $dates = asTenant($tenant, fn () => $contract->installments()->orderBy('number')->get()->map(fn ($i) => $i->due_date->format('Y-m-d'))->all());
    expect($dates)->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('starts every instalment unpaid', function () {
    $tenant = workspaceOn();
    $contract = app(CreateContract::class)->handle($tenant, contractData(customerIn($tenant)));

    $rows = asTenant($tenant, fn () => $contract->installments()->get());

    expect($rows->every(fn (Installment $i) => $i->status === 'pending' && Money::isZero($i->paid_amount) && $i->paid_at === null))->toBeTrue();
});

it('records the down payment in the ledger as money received on the contract date', function () {
    $tenant = workspaceOn();
    $user = User::factory()->create();

    $contract = app(CreateContract::class)->handle($tenant, contractData(customerIn($tenant)), $user);

    $ledger = asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->get());
    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]->type)->toBe('down_payment')
        ->and(Money::cmp($ledger[0]->amount, '200.00'))->toBe(0)
        ->and($ledger[0]->method)->toBe('cash')
        ->and($ledger[0]->paid_at->format('Y-m-d'))->toBe('2026-01-15')
        ->and($ledger[0]->customer_id)->toBe($contract->customer_id)
        ->and($ledger[0]->created_by_user_id)->toBe($user->id);
    expectConsistentLedger($contract);
});

it('records nothing in the ledger when there is no down payment', function () {
    $tenant = workspaceOn();

    $contract = app(CreateContract::class)->handle($tenant, contractData(customerIn($tenant), ['down_payment' => '0']));

    expect(asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count()))->toBe(0);
});

it('opens a cash sale as one instalment due on the sale date', function () {
    $tenant = workspaceOn();
    $customer = customerIn($tenant);

    $contract = app(CreateContract::class)->handle($tenant, [
        'customer_id' => $customer->id, 'type' => 'cash', 'principal' => '450.00', 'start_date' => '2026-03-10',
    ]);

    $rows = asTenant($tenant, fn () => $contract->installments()->get());
    expect($contract->type)->toBe('cash')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->due_date->format('Y-m-d'))->toBe('2026-03-10')
        ->and(Money::cmp($rows[0]->amount, '450.00'))->toBe(0)
        ->and(Money::cmp($contract->total, '450.00'))->toBe(0);
});

it('numbers contracts per workspace, starting at 1', function () {
    $a = workspaceOn();
    $b = workspaceOn();
    $customerA = customerIn($a);
    $customerB = customerIn($b);

    $first = app(CreateContract::class)->handle($a, contractData($customerA));
    $second = app(CreateContract::class)->handle($a, contractData($customerA));
    $other = app(CreateContract::class)->handle($b, contractData($customerB));

    expect($first->number)->toBe(1)->and($second->number)->toBe(2)->and($other->number)->toBe(1)
        ->and($second->reference())->toBe('C-0002');
});

it('records who opened it and starts active', function () {
    $tenant = workspaceOn();
    $user = User::factory()->create();

    $contract = app(CreateContract::class)->handle($tenant, contractData(customerIn($tenant)), $user);

    expect($contract->created_by_user_id)->toBe($user->id)->and($contract->status)->toBe('active')
        ->and($contract->tenant_id)->toBe($tenant->id)->and(app(CurrentTenant::class)->get())->toBeNull();
});

describe('the plan limit', function () {
    // About how a limit behaves, so it sets one (the free plan has no contract limit of its own).
    beforeEach(fn () => limitFreePlan(Feature::ActiveContracts, 5));

    it('lets a workspace hold the active contracts its plan allows and refuses the next, creating nothing', function () {
        $tenant = workspaceOn('free');
        $customer = customerIn($tenant);
        fillContracts($tenant, $customer, 5);
        $installmentsBefore = installmentsOf($tenant);

        expect(fn () => app(CreateContract::class)->handle($tenant, contractData($customer)))->toThrow(LimitReached::class);

        expect(contractsOf($tenant))->toBe(5)->and(installmentsOf($tenant))->toBe($installmentsBefore);
    });

    it('says which limit was reached', function () {
        $tenant = workspaceOn('free');
        $customer = customerIn($tenant);
        fillContracts($tenant, $customer, 5);

        try {
            app(CreateContract::class)->handle($tenant, contractData($customer));
            $this->fail('Expected LimitReached');
        } catch (LimitReached $e) {
            expect($e->feature)->toBe(Feature::ActiveContracts)->and($e->limit)->toBe(5)->and($e->used)->toBe(5);
        }
    });

    it('does not count settled or cancelled contracts as active', function (string $status) {
        $tenant = workspaceOn('free');
        $customer = customerIn($tenant);
        fillContracts($tenant, $customer, 5);
        asTenant($tenant, fn () => Contract::first()->forceFill(['status' => $status])->save());

        app(CreateContract::class)->handle($tenant, contractData($customer));

        expect(contractsOf($tenant))->toBe(6);
    })->with(['settled', 'cancelled']);

    it('never stops a Pro workspace', function () {
        $tenant = workspaceOn('pro');
        fillContracts($tenant, customerIn($tenant), 8);

        expect(contractsOf($tenant))->toBe(8);
    });

    it('is separate from the customer limit and from other workspaces', function () {
        $a = workspaceOn('free');
        $b = workspaceOn('free');
        fillContracts($a, customerIn($a), 5);

        app(CreateContract::class)->handle($b, contractData(customerIn($b)));

        expect(contractsOf($b))->toBe(1);
    });

    it('reports real usage through the entitlement meter', function () {
        $tenant = workspaceOn('free');
        fillContracts($tenant, customerIn($tenant), 2);

        $entitlement = Entitlements::for($tenant)->check(Feature::ActiveContracts);

        expect($entitlement->used())->toBe(2)->and($entitlement->remaining())->toBe(3);
    });

    it('keeps every contract after a downgrade and only blocks new ones', function () {
        $tenant = workspaceOn('pro');
        $customer = customerIn($tenant);
        fillContracts($tenant, $customer, 7);

        $tenant->subscribeTo(Plan::where('key', 'free')->sole());

        expect(contractsOf($tenant))->toBe(7);
        expect(fn () => app(CreateContract::class)->handle($tenant, contractData($customer)))->toThrow(LimitReached::class);
    });
});

describe('who the contract is for', function () {
    it('refuses a customer that belongs to another workspace, creating nothing', function () {
        $tenant = workspaceOn();
        $foreign = customerIn(workspaceOn());

        expect(fn () => app(CreateContract::class)->handle($tenant, contractData($foreign)))->toThrow(ModelNotFoundException::class);

        expect(contractsOf($tenant))->toBe(0)->and(installmentsOf($tenant))->toBe(0);
    });

    it('refuses a deleted customer', function () {
        $tenant = workspaceOn();
        $customer = customerIn($tenant);
        asTenant($tenant, fn () => $customer->delete());

        expect(fn () => app(CreateContract::class)->handle($tenant, contractData($customer)))->toThrow(ModelNotFoundException::class);
    });
});

describe('a schedule that cannot be built', function () {
    it('is reported as a validation error and consumes no contract number', function () {
        $tenant = workspaceOn();
        $customer = customerIn($tenant);

        expect(fn () => app(CreateContract::class)->handle($tenant, contractData($customer, ['down_payment' => '1200.00'])))
            ->toThrow(ValidationException::class);
        expect(contractsOf($tenant))->toBe(0);

        $next = app(CreateContract::class)->handle($tenant, contractData($customer));
        expect($next->number)->toBe(1);
    });
});
