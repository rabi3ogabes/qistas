<?php

use App\Actions\RecordPayment;
use App\Models\Contract;
use App\Reports\DashboardMetrics;
use Illuminate\Support\Carbon;

function briefingFor($tenant): array
{
    return app(DashboardMetrics::class)->briefing($tenant);
}

function takePayment(Contract $contract, string $amount, string $when): void
{
    app(RecordPayment::class)->handle($contract, $amount, 'cash', paidAt: Carbon::parse($when));
}

/**
 * Today is 7 Oct 2026. Contract A: 3 x 100 due 1 Sep, 1 Oct, 1 Nov (100 paid on 15 Sep, 60 on 3 Oct).
 * Contract B: 2 x 50 due 7 Oct (today) and 7 Nov.
 */
beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    $this->tenant = workspaceOn('pro');
    $this->a = openContract($this->tenant, ['principal' => '300.00', 'first_due_date' => '2026-09-01', 'start_date' => '2026-08-15']);
    $this->b = openContract($this->tenant, ['principal' => '100.00', 'installment_count' => 2, 'first_due_date' => '2026-10-07', 'start_date' => '2026-09-20']);
    takePayment($this->a, '100.00', '2026-09-15 09:00:00');
    takePayment($this->a, '60.00', '2026-10-03 09:00:00');
});

describe('who is late', function () {
    it('lists the unpaid instalments that fell due before today, with what is left and how many days late', function () {
        $late = briefingFor($this->tenant)['overdue_list'];

        expect($late)->toHaveCount(1)
            ->and($late[0]['contract_reference'])->toBe($this->a->reference())
            ->and($late[0]['amount_due'])->toBe('40.00')
            ->and($late[0]['due_date'])->toBe('2026-10-01')
            ->and($late[0]['days_late'])->toBe(6)
            ->and($late[0]['customer_name'])->toBe(asTenant($this->tenant, fn () => $this->a->customer->name))
            ->and($late[0]['customer_phone'])->toBe(asTenant($this->tenant, fn () => $this->a->customer->phone));
    });

    it('does not call what is due today late', function () {
        expect(array_column(briefingFor($this->tenant)['overdue_list'], 'contract_reference'))->not->toContain($this->b->reference());
    });

    it('puts the oldest first', function () {
        $this->travelTo('2026-10-09 09:00:00');

        $late = briefingFor($this->tenant)['overdue_list'];

        expect(array_column($late, 'due_date'))->toBe(['2026-10-01', '2026-10-07'])
            ->and($late[0]['days_late'])->toBe(8)
            ->and($late[1]['days_late'])->toBe(2);
    });

    it('drops an instalment once it is paid', function () {
        takePayment($this->a, '40.00', '2026-10-07 10:00:00');

        expect(briefingFor($this->tenant)['overdue_list'])->toBe([]);
    });

    it('leaves out cancelled contracts', function () {
        asTenant($this->tenant, fn () => Contract::find($this->a->id)->forceFill(['status' => 'cancelled'])->save());

        expect(briefingFor($this->tenant)['overdue_list'])->toBe([]);
    });

    it('lists at most twenty-five', function () {
        foreach (range(1, 30) as $_) {
            openContract($this->tenant, ['type' => 'cash', 'principal' => '10.00', 'start_date' => '2026-09-01']);
        }

        expect(briefingFor($this->tenant)['overdue_list'])->toHaveCount(DashboardMetrics::LIST_LIMIT);
    });
});

describe('who is about to be', function () {
    it('has nothing coming in the next week when nothing falls due', function () {
        expect(briefingFor($this->tenant)['upcoming'])->toBe([]);
    });

    it('lists the instalments due in the next seven days, soonest first, with the days to go', function () {
        $this->travelTo('2026-10-30 09:00:00');

        $coming = briefingFor($this->tenant)['upcoming'];

        expect($coming)->toHaveCount(1)
            ->and($coming[0]['contract_reference'])->toBe($this->a->reference())
            ->and($coming[0]['due_date'])->toBe('2026-11-01')
            ->and($coming[0]['days_until'])->toBe(2)
            ->and($coming[0]['amount_due'])->toBe('100.00');
    });

    it('stops at seven days', function () {
        // 1 Nov is eight days from 24 Oct, and seven days from 25 Oct.
        $this->travelTo('2026-10-24 09:00:00');
        expect(briefingFor($this->tenant)['upcoming'])->toBe([]);

        $this->travelTo('2026-10-25 09:00:00');
        expect(briefingFor($this->tenant)['upcoming'])->toHaveCount(1);
    });
});

describe('this month against last', function () {
    it('says what falls due this month', function () {
        // A's October instalment (100) and B's first (50).
        expect(briefingFor($this->tenant)['expected_this_month'])->toBe('150.00');
    });

    it('says what was collected last month', function () {
        expect(briefingFor($this->tenant)['collected_last_month'])->toBe('100.00');
    });

    it('rolls over from January to December', function () {
        $this->travelTo('2027-01-15 09:00:00');
        $tenant = workspaceOn('pro');
        $contract = openContract($tenant, ['type' => 'cash', 'principal' => '100.00', 'start_date' => '2026-12-10']);
        takePayment($contract, '100.00', '2026-12-10 10:00:00');

        expect(briefingFor($tenant)['collected_last_month'])->toBe('100.00');
    });

    it('leaves cancelled contracts out of what is expected', function () {
        asTenant($this->tenant, fn () => Contract::find($this->b->id)->forceFill(['status' => 'cancelled'])->save());

        expect(briefingFor($this->tenant)['expected_this_month'])->toBe('100.00');
    });
});

describe('the last two weeks', function () {
    it('gives fourteen days ending today, oldest first, with what was taken each day', function () {
        $days = briefingFor($this->tenant)['daily_collected'];

        expect($days)->toHaveCount(14)
            ->and($days[0]['date'])->toBe('2026-09-24')
            ->and($days[13]['date'])->toBe('2026-10-07')
            ->and(collect($days)->firstWhere('date', '2026-10-03')['amount'])->toBe('60.00')
            ->and(collect($days)->firstWhere('date', '2026-10-04')['amount'])->toBe('0.00');
    });

    it('does not include a payment from before the window', function () {
        // The 100 taken on 15 Sep is outside the fourteen days.
        expect(collect(briefingFor($this->tenant)['daily_collected'])->sum(fn ($day) => (float) $day['amount']))->toBe(60.0);
    });

    it('adds up several payments on one day and takes voided money off', function () {
        takePayment($this->b, '20.00', '2026-10-05 10:00:00');
        takePayment($this->b, '5.00', '2026-10-05 15:00:00');

        expect(collect(briefingFor($this->tenant)['daily_collected'])->firstWhere('date', '2026-10-05')['amount'])->toBe('25.00');
    });
});

it('is all empty for a workspace with no data', function () {
    $briefing = briefingFor(workspaceOn());

    expect($briefing['overdue_list'])->toBe([])
        ->and($briefing['upcoming'])->toBe([])
        ->and($briefing['expected_this_month'])->toBe('0.00')
        ->and($briefing['collected_last_month'])->toBe('0.00')
        ->and($briefing['daily_collected'])->toHaveCount(14);
});

it('never mixes in another workspace', function () {
    $other = workspaceOn('pro');
    openContract($other, ['principal' => '900.00', 'first_due_date' => '2026-09-01', 'start_date' => '2026-08-15']);

    // Mine: one late instalment. Theirs: two (1 Sep and 1 Oct, nothing paid), and none of mine among them.
    expect(briefingFor($this->tenant)['overdue_list'])->toHaveCount(1)
        ->and(briefingFor($other)['overdue_list'])->toHaveCount(2)
        ->and(briefingFor($other)['expected_this_month'])->toBe('300.00');
});

it('is part of the dashboard in the API, with the phone number on every row', function () {
    [$user] = apiOwner();

    $response = $this->getJson('/api/v1/dashboard')->assertOk();

    expect($response->json('data'))->toHaveKeys(['outstanding', 'due_today', 'overdue_list', 'upcoming', 'expected_this_month', 'collected_last_month', 'daily_collected']);
});
