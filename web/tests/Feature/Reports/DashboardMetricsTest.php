<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\Contract;
use App\Reports\DashboardMetrics;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;

function metricsFor($tenant): array
{
    return app(DashboardMetrics::class)->for($tenant);
}

function receive(Contract $contract, string $amount, string $when): void
{
    app(RecordPayment::class)->handle($contract, $amount, 'cash', paidAt: Carbon::parse($when));
}

/**
 * Today is 7 Oct 2026. Contract A (customer 1): 3 x 100 due 1 Sep, 1 Oct, 1 Nov; contract B (customer 2): 2 x 50 due
 * 7 Oct and 7 Nov. A has paid 100 on 15 Sep and 60 on 3 Oct, so its October instalment is 60/100 paid.
 */
beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    $this->tenant = workspaceOn('pro');
    $this->a = openContract($this->tenant, ['principal' => '300.00', 'first_due_date' => '2026-09-01', 'start_date' => '2026-08-15']);
    $this->b = openContract($this->tenant, ['principal' => '100.00', 'installment_count' => 2, 'first_due_date' => '2026-10-07', 'start_date' => '2026-09-20']);
    receive($this->a, '100.00', '2026-09-15 09:00:00');
    receive($this->a, '60.00', '2026-10-03 09:00:00');
});

it('adds up what is still owed on running contracts', function () {
    // A: 40 + 100 left, B: 50 + 50.
    expect(metricsFor($this->tenant)['outstanding'])->toBe('240.00');
});

it('adds up what is owed and past due, not counting today', function () {
    // A's October instalment (due 1 Oct) is 40 short; B's first instalment is due today, so not late yet.
    expect(metricsFor($this->tenant)['overdue'])->toBe('40.00');
});

it('counts the day after the due date as overdue', function () {
    $this->travelTo('2026-10-08 08:00:00');

    expect(metricsFor($this->tenant)['overdue'])->toBe('90.00');
});

it('adds up the money received this calendar month', function () {
    expect(metricsFor($this->tenant)['collected_this_month'])->toBe('60.00');
});

it('counts down payments received this month, and takes voided payments off', function () {
    openContract($this->tenant, ['principal' => '500.00', 'down_payment' => '30.00', 'start_date' => '2026-10-02', 'first_due_date' => '2026-11-01']);
    expect(metricsFor($this->tenant)['collected_this_month'])->toBe('90.00');

    $payment = app(RecordPayment::class)->handle($this->b, '20.00', 'cash', paidAt: Carbon::parse('2026-10-05 10:00:00'));
    expect(metricsFor($this->tenant)['collected_this_month'])->toBe('110.00');

    app(VoidTransaction::class)->handle($payment);
    expect(metricsFor($this->tenant)['collected_this_month'])->toBe('90.00');
});

it('draws the month boundary exactly', function () {
    $contract = openContract($this->tenant, ['principal' => '300.00', 'first_due_date' => '2026-12-01', 'start_date' => '2026-09-01']);
    receive($contract, '1.00', '2026-09-30 23:59:59');
    receive($contract, '2.00', '2026-10-01 00:00:00');
    receive($contract, '4.00', '2026-10-07 11:59:59');

    // 60 from before, plus the 2 and the 4; the 1 belongs to September.
    expect(metricsFor($this->tenant)['collected_this_month'])->toBe('66.00');
});

it('counts customers who have a running contract', function () {
    expect(metricsFor($this->tenant)['active_customers'])->toBe(2);

    receive($this->b, '100.00', '2026-10-07 09:00:00');
    expect(metricsFor($this->tenant)['active_customers'])->toBe(1);

    customerIn($this->tenant); // a customer without any contract is not "active"
    expect(metricsFor($this->tenant)['active_customers'])->toBe(1);
});

it('counts a person once however many contracts they have', function () {
    $customerId = asTenant($this->tenant, fn () => Contract::find($this->a->id)->customer_id);
    openContract($this->tenant, ['customer_id' => $customerId, 'first_due_date' => '2026-12-01']);

    expect(metricsFor($this->tenant)['active_customers'])->toBe(2);
});

it('includes the first and last day of the month in the collection rate', function () {
    $this->travelTo('2026-11-15 12:00:00');
    $tenant = workspaceOn('pro');
    $first = openContract($tenant, ['type' => 'cash', 'principal' => '100.00', 'start_date' => '2026-11-01']);
    openContract($tenant, ['type' => 'cash', 'principal' => '100.00', 'start_date' => '2026-11-30']);
    openContract($tenant, ['type' => 'cash', 'principal' => '100.00', 'start_date' => '2026-10-31']);
    openContract($tenant, ['type' => 'cash', 'principal' => '100.00', 'start_date' => '2026-12-01']);
    receive($first, '100.00', '2026-11-10 10:00:00');

    // Only the 1 Nov and 30 Nov instalments fall due in November: 100 of 200.
    expect(metricsFor($tenant)['collection_rate'])->toBe('50.0');
});

it('lists what falls due today, with who owes it', function () {
    $due = metricsFor($this->tenant)['due_today'];

    expect($due)->toHaveCount(1)
        ->and($due[0]['contract_reference'])->toBe($this->b->reference())
        ->and($due[0]['customer_name'])->toBe(asTenant($this->tenant, fn () => $this->b->customer->name))
        ->and($due[0]['amount_due'])->toBe('50.00')
        ->and($due[0]['due_date'])->toBe('2026-10-07');
});

it('shows only what is still owed on an instalment due today', function () {
    receive($this->b, '20.00', '2026-10-07 09:00:00');

    expect(metricsFor($this->tenant)['due_today'][0]['amount_due'])->toBe('30.00');
});

it('drops a due-today instalment once it is paid', function () {
    receive($this->b, '50.00', '2026-10-07 09:00:00');

    expect(metricsFor($this->tenant)['due_today'])->toBe([]);
});

it('measures how much of this month’s instalments has been collected', function () {
    // Due in October: A's 100 (60 paid) and B's 50 (nothing paid) -> 60 of 150.
    expect(metricsFor($this->tenant)['collection_rate'])->toBe('40.0');

    receive($this->b, '50.00', '2026-10-07 09:00:00');
    expect(metricsFor($this->tenant)['collection_rate'])->toBe('73.3');
});

it('gives no collection rate when nothing falls due this month', function () {
    $this->travelTo('2027-06-15 12:00:00');

    expect(metricsFor($this->tenant)['collection_rate'])->toBeNull();
});

it('leaves cancelled contracts out of every figure', function () {
    asTenant($this->tenant, fn () => Contract::find($this->b->id)->forceFill(['status' => 'cancelled'])->save());
    $metrics = metricsFor($this->tenant);

    expect($metrics['outstanding'])->toBe('140.00')
        ->and($metrics['overdue'])->toBe('40.00')
        ->and($metrics['active_customers'])->toBe(1)
        ->and($metrics['due_today'])->toBe([])
        ->and($metrics['collection_rate'])->toBe('60.0');
});

it('counts a settled contract’s paid instalments in the collection rate, but not as outstanding', function () {
    receive($this->a, '140.00', '2026-10-07 09:00:00');
    $metrics = metricsFor($this->tenant);

    expect(asTenant($this->tenant, fn () => Contract::find($this->a->id)->status))->toBe('settled')
        ->and($metrics['outstanding'])->toBe('100.00')
        ->and($metrics['active_customers'])->toBe(1)
        ->and($metrics['collection_rate'])->toBe('66.7');
});

it('never mixes in another workspace', function () {
    $other = workspaceOn('pro');
    $theirs = openContract($other, ['principal' => '900.00', 'first_due_date' => '2026-10-07']);
    receive($theirs, '300.00', '2026-10-06 10:00:00');

    $mine = metricsFor($this->tenant);

    expect($mine['outstanding'])->toBe('240.00')->and($mine['collected_this_month'])->toBe('60.00')
        ->and($mine['active_customers'])->toBe(2)->and($mine['due_today'])->toHaveCount(1);
    expect(metricsFor($other)['outstanding'])->toBe('600.00');
});

it('is all zeros for a workspace with no data', function () {
    $metrics = metricsFor(workspaceOn());

    expect($metrics)->toBe([
        'outstanding' => '0.00', 'overdue' => '0.00', 'collected_this_month' => '0.00',
        'active_customers' => 0, 'collection_rate' => null, 'due_today' => [],
    ]);
});

it('returns exact money strings, never floats', function () {
    $metrics = metricsFor($this->tenant);

    foreach (['outstanding', 'overdue', 'collected_this_month'] as $key) {
        expect($metrics[$key])->toBeString()->toMatch('/^-?\d+\.\d{2}$/');
    }
});

it('does not leave a workspace switched on', function () {
    metricsFor($this->tenant);

    expect(app(CurrentTenant::class)->get())->toBeNull();
});

it('lists at most fifty instalments due today, in customer-name order', function () {
    foreach (range(1, 55) as $_) {
        openContract($this->tenant, ['type' => 'cash', 'principal' => '10.00', 'start_date' => '2026-10-07']);
    }

    $due = metricsFor($this->tenant)['due_today'];
    $names = array_column($due, 'customer_name');
    $sorted = $names;
    sort($sorted, SORT_STRING | SORT_FLAG_CASE);

    expect($due)->toHaveCount(50)->and($names)->toBe($sorted);
});
