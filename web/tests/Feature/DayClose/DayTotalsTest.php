<?php

use App\Actions\CancelContract;
use App\Actions\RecordCharge;
use App\Actions\VoidTransaction;
use App\Actions\WaiveLateFee;
use App\Entitlements\Feature;
use App\Entitlements\FeatureGroup;
use App\Entitlements\PlatformState;
use App\Models\Customer;
use App\Models\DayClose;
use App\Models\LateFee;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\SettingsRegistry;
use App\Settings\TenantSettings;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Daily-habit wave A, H4: the evening ritual compares the cash a person counted with what Qistas recorded for the
| workspace's day. DayTotals is the one place that works that day's figures out, so closing, reopening, the API and
| both screens all agree. A day is the workspace's own calendar day (WorkspaceDay), cut in SQL as
| [start, end) on the timestamp column, never by the database's own date.
|
| The workspace is in Saudi Arabia (Asia/Riyadh, UTC+3, no daylight saving) unless a test says otherwise, so 21:00 UTC
| is midnight there: the 21st runs from 2026-10-20 21:00 UTC up to, not including, 2026-10-21 21:00 UTC.
*/

// closeShop, closeStaff, closeContract, closeTake and closeTotals live in tests/Pest.php, shared by the day-close tests.

/** A day's close written straight to the table, by trusted code, for the tests of the row itself (CloseDay writes the real ones). */
function closeRow(Tenant $tenant, User $by, array $overrides = []): DayClose
{
    return asTenant($tenant, function () use ($by, $overrides): DayClose {
        $close = (new DayClose)->forceFill(array_merge([
            'day' => '2026-10-21', 'version' => 1, 'status' => 'closed',
            'expected_cash' => '120.00', 'counted_cash' => '118.00', 'difference' => '-2.00',
            'totals' => ['by_method' => ['cash' => '120.00'], 'count' => 3, 'per_member' => []],
            'note' => 'Two riyals short', 'closed_by_user_id' => $by->id, 'closed_at' => now(),
            'snapshot_hash' => str_repeat('a', 64),
        ], $overrides));
        $close->save();

        return $close->fresh();
    });
}

beforeEach(function () {
    // Everything is set up in the early morning of the 1st, then the clock moves to the 21st at noon UTC (15:00 in Riyadh).
    $this->travelTo('2026-10-01 06:00:00');
    $this->shop = closeShop();
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->omar = closeStaff($this->shop, 'Omar Collector');
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->contract = closeContract($this->shop);
    $this->travelTo('2026-10-21 12:00:00');
});

// Expected cash

test('expected cash is the cash taken today minus the cash voided today', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    $mistake = closeTake($this->contract, '40.00', by: $this->omar);
    app(VoidTransaction::class)->handle($mistake, 'Entered twice', $this->boss);

    $totals = closeTotals($this->shop);

    expect($totals['day'])->toBe('2026-10-21')
        ->and($totals['expected_cash'])->toBe('100.00')
        ->and($totals['by_method'])->toBe(['cash' => '100.00']);
});

it('counts the payments that stand, and not the one voided the same day', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    $mistake = closeTake($this->contract, '40.00', by: $this->omar);
    closeTake($this->contract, '60.00', 'card', by: $this->omar);
    app(VoidTransaction::class)->handle($mistake, null, $this->boss);

    expect(closeTotals($this->shop)['count'])->toBe(2);
});

it('counts down payments as money in', function () {
    closeContract($this->shop, 'Layla Hassan', ['down_payment' => '250.00', 'start_date' => '2026-10-21']);

    $totals = closeTotals($this->shop);

    expect($totals['expected_cash'])->toBe('250.00')
        ->and($totals['count'])->toBe(1);
});

it('excludes an open contract\'s charges', function () {
    switchOn(Feature::OpenContracts);
    // What the customer took (an opening balance, a later line) is owed to the shop, never money that came in.
    $tab = closeContract($this->shop, 'Open Tab', ['type' => 'open', 'opening_balance' => '500.00', 'start_date' => '2026-10-21']);
    app(RecordCharge::class)->handle($tab, '75.00', by: $this->sara);

    $totals = closeTotals($this->shop);

    expect($totals['expected_cash'])->toBe('0.00')
        ->and($totals['by_method'])->toBe([])
        ->and($totals['count'])->toBe(0)
        ->and($totals['per_member'])->toBe([])
        ->and($totals['non_cash'])->toBe([]);
});

it('counts what an open contract\'s customer pays on account', function () {
    switchOn(Feature::OpenContracts);
    $tab = closeContract($this->shop, 'Open Tab', ['type' => 'open', 'opening_balance' => '500.00', 'start_date' => '2026-10-21']);

    closeTake($tab, '120.00', by: $this->sara);

    $totals = closeTotals($this->shop);
    expect($totals['expected_cash'])->toBe('120.00')
        ->and($totals['by_method'])->toBe(['cash' => '120.00'])
        ->and($totals['count'])->toBe(1);
});

test('a void today of yesterday\'s cash lowers today\'s expected cash, and yesterday keeps what it had', function () {
    $this->travelTo('2026-10-20 09:00:00');
    $yesterdays = closeTake($this->contract, '100.00', by: $this->sara);
    $this->travelTo('2026-10-21 09:00:00');
    closeTake($this->contract, '30.00', by: $this->sara);
    app(VoidTransaction::class)->handle($yesterdays, 'Customer was refunded', $this->boss);

    $today = closeTotals($this->shop);
    $yesterday = closeTotals($this->shop, '2026-10-20');

    expect($today['expected_cash'])->toBe('-70.00')
        ->and($today['count'])->toBe(1)
        ->and($yesterday['expected_cash'])->toBe('100.00')
        ->and($yesterday['count'])->toBe(1);
});

it('uses the workspace\'s day, not UTC', function () {
    $this->travelTo('2026-10-22 06:00:00');
    // 21:00 UTC is midnight in Riyadh: the first instant of the 21st is in, the first instant of the 22nd is out.
    closeTake($this->contract, '1.00', at: '2026-10-20 20:59:59');   // the 20th's last second
    closeTake($this->contract, '2.00', at: '2026-10-20 21:00:00');   // the 21st's first instant
    closeTake($this->contract, '4.00', at: '2026-10-21 20:50:00');   // 23:50 on the 21st, still the 21st's UTC date
    closeTake($this->contract, '8.00', at: '2026-10-21 20:59:59');   // the 21st's last second
    closeTake($this->contract, '16.00', at: '2026-10-21 21:00:00');  // 00:00 on the 22nd, yet the 21st's UTC date

    expect(closeTotals($this->shop, '2026-10-20')['expected_cash'])->toBe('1.00')
        ->and(closeTotals($this->shop, '2026-10-21')['expected_cash'])->toBe('14.00')
        ->and(closeTotals($this->shop, '2026-10-21')['count'])->toBe(3)
        ->and(closeTotals($this->shop, '2026-10-22')['expected_cash'])->toBe('16.00');
});

it('follows a day that is 25 or 23 hours long when the clocks change', function () {
    $this->travelTo('2026-10-01 06:00:00');
    $newYork = closeShop('US', 'America/New_York');
    $contract = closeContract($newYork);
    $this->travelTo('2026-11-05 12:00:00');

    // The clocks go back on 1 Nov: that day runs from 04:00 UTC to 05:00 UTC the next day, 25 hours.
    closeTake($contract, '1.00', at: '2026-11-01 03:59:59');  // 23:59:59 on 31 Oct
    closeTake($contract, '2.00', at: '2026-11-01 04:00:00');  // midnight, the first instant of 1 Nov
    closeTake($contract, '4.00', at: '2026-11-02 04:59:59');  // 23:59:59 on 1 Nov, in the 25th hour
    closeTake($contract, '8.00', at: '2026-11-02 05:00:00');  // midnight on 2 Nov

    // They go forward on 8 Mar: that day runs from 05:00 UTC to 04:00 UTC the next day, 23 hours.
    closeTake($contract, '16.00', at: '2026-03-08 05:00:00');  // midnight, the first instant of 8 Mar
    closeTake($contract, '32.00', at: '2026-03-09 03:59:59');  // 23:59:59 on 8 Mar
    closeTake($contract, '64.00', at: '2026-03-09 04:00:00');  // midnight on 9 Mar

    expect(closeTotals($newYork, '2026-11-01')['expected_cash'])->toBe('6.00')
        ->and(closeTotals($newYork, '2026-11-02')['expected_cash'])->toBe('8.00')
        ->and(closeTotals($newYork, '2026-03-08')['expected_cash'])->toBe('48.00')
        ->and(closeTotals($newYork, '2026-03-09')['expected_cash'])->toBe('64.00');
});

// Per member

test('per-member totals add up to the day\'s cash', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    $fifty = closeTake($this->contract, '50.00', by: $this->sara);
    closeTake($this->contract, '70.00', by: $this->omar);
    closeTake($this->contract, '25.00', 'card', by: $this->omar);
    app(VoidTransaction::class)->handle($fifty, null, $this->boss);

    $totals = closeTotals($this->shop);

    // Each line belongs to the person who recorded it: the void is the owner's, so it is the owner's line that falls.
    expect($totals['expected_cash'])->toBe('170.00')
        ->and($totals['per_member'])->toBe([
            $this->boss->id => ['name' => 'Boss Owner', 'cash' => '-50.00', 'count' => 0],
            $this->omar->id => ['name' => 'Omar Collector', 'cash' => '70.00', 'count' => 1],
            $this->sara->id => ['name' => 'Sara Collector', 'cash' => '150.00', 'count' => 1],
        ]);

    $sum = array_reduce($totals['per_member'], fn (string $carry, array $member) => bcadd($carry, $member['cash'], 2), '0.00');
    expect($sum)->toBe($totals['expected_cash']);
});

it('keeps the per-member money of someone who has since left the team, under a name that says so', function () {
    closeTake($this->contract, '80.00', by: $this->sara);
    $this->sara->delete();

    $totals = closeTotals($this->shop);

    expect($totals['expected_cash'])->toBe('80.00')
        ->and($totals['per_member'])->toBe(['unknown' => ['name' => __('Someone no longer on the team'), 'cash' => '80.00', 'count' => 1]]);
});

it('gives each member a name and two figures, never an e-mail address or a token', function () {
    closeTake($this->contract, '10.00', by: $this->sara);

    $member = closeTotals($this->shop)['per_member'][$this->sara->id];

    expect(array_keys($member))->toBe(['name', 'cash', 'count'])
        ->and(json_encode(closeTotals($this->shop)))->not->toContain($this->sara->email);
});

// Non-cash payments

it('ignores card and bank transfers in the cash figure but lists them', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    $this->travelTo('2026-10-21 12:05:00');
    $transfer = closeTake($this->contract, '200.00', 'bank_transfer', by: $this->omar);
    $this->travelTo('2026-10-21 12:10:00');
    $card = closeTake($this->contract, '75.50', 'card', by: $this->sara);

    $totals = closeTotals($this->shop);

    expect($totals['expected_cash'])->toBe('100.00')
        ->and($totals['by_method'])->toBe(['cash' => '100.00', 'bank_transfer' => '200.00', 'card' => '75.50'])
        ->and($totals['count'])->toBe(3)
        ->and($totals['non_cash'])->toBe([
            ['id' => $transfer->id, 'customer' => 'Ahmad Ali', 'amount' => '200.00', 'method' => 'bank_transfer', 'note' => null],
            ['id' => $card->id, 'customer' => 'Ahmad Ali', 'amount' => '75.50', 'method' => 'card', 'note' => null],
        ]);
});

it('lists a transfer that was voided as its own negative line, with the reason as its note', function () {
    $transfer = closeTake($this->contract, '300.00', 'bank_transfer', by: $this->sara);
    $this->travelTo('2026-10-21 13:00:00');
    $reversal = app(VoidTransaction::class)->handle($transfer, 'Wrong customer', $this->boss);

    $lines = closeTotals($this->shop)['non_cash'];

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['amount'])->toBe('300.00')
        ->and($lines[1])->toBe(['id' => $reversal->id, 'customer' => 'Ahmad Ali', 'amount' => '-300.00', 'method' => 'bank_transfer', 'note' => 'Wrong customer'])
        ->and(closeTotals($this->shop)['by_method'])->toBe(['bank_transfer' => '0.00']);
});

it('still names a customer who was deleted afterwards', function () {
    closeTake($this->contract, '45.00', 'card', by: $this->sara);
    asTenant($this->shop, fn () => Customer::query()->whereKey($this->contract->customer_id)->firstOrFail()->delete());

    expect(closeTotals($this->shop)['non_cash'][0]['customer'])->toBe('Ahmad Ali');
});

// Worth a look

it('lists voids, waived fees, back-dated payments, contracts opened and contracts cancelled in watch', function () {
    // A contract from earlier in the month: one of its payments is voided today, a fee on it is waived, then it is cancelled.
    $this->travelTo('2026-10-02 10:00:00');
    $older = closeContract($this->shop, 'Noor Khan');
    $installment = installmentsOfContract($older)->first();
    $fee = asTenant($this->shop, function () use ($older, $installment): LateFee {
        $fee = (new LateFee)->forceFill([
            'contract_id' => $older->id, 'installment_id' => $installment->id, 'period' => 1, 'charged_on' => '2026-10-10',
            'amount' => '25.00', 'paid_amount' => '5.00', 'status' => 'open', 'charity' => false,
        ]);
        $fee->save();

        return $fee;
    });
    $oldPayment = closeTake($older, '100.00', by: $this->sara);

    $this->travelTo('2026-10-21 12:00:00');
    $reversal = app(VoidTransaction::class)->handle($oldPayment, 'Entered on the wrong day', $this->boss);
    app(WaiveLateFee::class)->handle($fee, 'Goodwill', $this->boss);
    $backDated = closeTake($this->contract, '60.00', by: $this->omar, at: '2026-10-18 10:00:00');
    $fresh = closeContract($this->shop, 'Layla Hassan', ['principal' => '1200.00', 'installment_count' => 6]);
    app(CancelContract::class)->handle($older, 'Customer changed their mind', $this->boss);

    $watch = closeTotals($this->shop)['watch'];

    expect($watch)->toBe([
        ['kind' => 'void', 'id' => $reversal->id, 'label' => 'Noor Khan', 'amount' => '100.00'],
        ['kind' => 'waived_fee', 'id' => $fee->id, 'label' => 'Noor Khan', 'amount' => '20.00'],
        ['kind' => 'back_dated', 'id' => $backDated->id, 'label' => 'Ahmad Ali', 'amount' => '60.00'],
        ['kind' => 'opened', 'id' => $fresh->id, 'label' => 'Layla Hassan', 'amount' => '1200.00'],
        ['kind' => 'cancelled', 'id' => $older->id, 'label' => 'Noor Khan', 'amount' => '5000.00'],
    ]);
});

it('names an open account that was opened today without an amount', function () {
    switchOn(Feature::OpenContracts);
    $tab = closeContract($this->shop, 'Open Tab', ['type' => 'open', 'start_date' => '2026-10-21']);

    expect(closeTotals($this->shop)['watch'])->toBe([['kind' => 'opened', 'id' => $tab->id, 'label' => 'Open Tab', 'amount' => null]]);
});

it('counts a back-dated payment on the day it belongs to, not the day it was recorded', function () {
    closeTake($this->contract, '60.00', by: $this->omar, at: '2026-10-18 10:00:00');

    expect(closeTotals($this->shop)['expected_cash'])->toBe('0.00')
        ->and(closeTotals($this->shop, '2026-10-18')['expected_cash'])->toBe('60.00')
        ->and(array_column(closeTotals($this->shop, '2026-10-18')['watch'], 'kind'))->toBe([]);
});

it('does not flag a payment taken and recorded on the same day, nor one recorded earlier', function () {
    $this->travelTo('2026-10-20 12:00:00');
    closeTake($this->contract, '15.00', by: $this->sara);
    $this->travelTo('2026-10-21 12:00:00');
    closeTake($this->contract, '25.00', by: $this->sara);

    expect(closeTotals($this->shop)['watch'])->toBe([]);
});

it('never carries a phone number, a national ID or an e-mail address', function () {
    closeTake($this->contract, '60.00', 'card', by: $this->omar, at: '2026-10-18 10:00:00');
    closeTake($this->contract, '10.00', 'bank_transfer', by: $this->sara);

    $text = json_encode(closeTotals($this->shop));

    expect($text)->toContain('Ahmad Ali')
        ->and($text)->not->toContain('966501234567')
        ->and($text)->not->toContain('1098765432')
        ->and($text)->not->toContain('ahmad@example.test');
});

// The whole day

test('an empty day returns zeros and empty lists', function () {
    expect(closeTotals(closeShop()))->toBe([
        'day' => '2026-10-21',
        'expected_cash' => '0.00',
        'by_method' => [],
        'count' => 0,
        'per_member' => [],
        'non_cash' => [],
        'watch' => [],
    ]);
});

test('another workspace\'s money is never counted', function () {
    $other = closeShop();
    $theirs = closeContract($other, 'Someone Else');
    closeTake($this->contract, '100.00', by: $this->sara);
    closeTake($theirs, '999.00');
    closeTake($theirs, '444.00', 'card');

    $mine = closeTotals($this->shop);
    $theirTotals = closeTotals($other);

    expect($mine['expected_cash'])->toBe('100.00')
        ->and($mine['by_method'])->toBe(['cash' => '100.00'])
        ->and($mine['non_cash'])->toBe([])
        ->and($mine['count'])->toBe(1)
        ->and(json_encode($mine))->not->toContain('Someone Else')
        ->and($theirTotals['expected_cash'])->toBe('999.00')
        ->and($theirTotals['by_method'])->toBe(['cash' => '999.00', 'card' => '444.00']);
});

it('works out the day for the workspace it is given and leaves the caller\'s workspace as it found it', function () {
    $other = closeShop();
    closeTake($this->contract, '100.00', by: $this->sara);

    expect(app(CurrentTenant::class)->get())->toBeNull();
    $inside = asTenant($other, function () {
        $totals = closeTotals($this->shop);

        return [$totals['expected_cash'], app(CurrentTenant::class)->get()->id];
    });

    expect($inside)->toBe(['100.00', $other->id])
        ->and(app(CurrentTenant::class)->get())->toBeNull();
});

it('asks the database a fixed number of questions, however many payments the day has', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        closeTotals($this->shop);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    closeTake($this->contract, '10.00', by: $this->sara);
    $few = $queries();

    for ($i = 0; $i < 6; $i++) {
        closeTake($this->contract, '5.00', 'cash', by: $i % 2 === 0 ? $this->sara : $this->omar);
        closeTake($this->contract, '7.00', 'card', by: $this->omar);
    }
    $voided = closeTake($this->contract, '3.00', by: $this->omar);
    app(VoidTransaction::class)->handle($voided, null, $this->boss);
    closeContract($this->shop, 'Another Customer');

    expect($queries())->toBe($few)
        ->and($few)->toBeLessThanOrEqual(10);
});

// The end-of-day close feature

it('is a Team toggle on for Free and Pro, and ships switched off like every new feature', function () {
    expect(Feature::DayClose->value)->toBe('day_close')
        ->and(Feature::DayClose->group())->toBe(FeatureGroup::Team)
        ->and(Feature::DayClose->isCore())->toBeFalse()
        ->and(Feature::DayClose->launchState())->toBe(PlatformState::Off)
        ->and(Feature::DayClose->defaultFor('free'))->toBe(['enabled' => true, 'limit' => null])
        ->and(Feature::DayClose->defaultFor('pro'))->toBe(['enabled' => true, 'limit' => null]);
});

it('offers the closing time as a half-hourly choice from 17:00 to 23:30, 20:00 until the owner decides', function () {
    $definition = app(SettingsRegistry::class)->find('day_close.closing_time');

    expect($definition->feature)->toBe(Feature::DayClose)
        ->and($definition->type)->toBe('select')
        ->and($definition->default)->toBe('20:00')
        ->and(array_column($definition->optionList(), 'value'))->toBe([
            '17:00', '17:30', '18:00', '18:30', '19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00', '22:30', '23:00', '23:30',
        ])
        ->and(TenantSettings::for($this->shop)->get('day_close.closing_time'))->toBe('20:00');
});

it('shows the closing time only while the feature is on, and takes only a time from the list', function () {
    $registry = app(SettingsRegistry::class);
    $keys = fn () => array_map(fn ($definition) => $definition->key, $registry->definitionsFor($this->shop));

    expect($keys())->not->toContain('day_close.closing_time');

    switchOn(Feature::DayClose);
    expect($keys())->toContain('day_close.closing_time');

    TenantSettings::for($this->shop)->set('day_close.closing_time', '21:30', $this->boss);
    expect(TenantSettings::for($this->shop)->get('day_close.closing_time'))->toBe('21:30');

    foreach (['16:30', '20:15', '24:00', '8 pm', ''] as $bad) {
        expect(fn () => TenantSettings::for($this->shop)->set('day_close.closing_time', $bad, $this->boss))->toThrow(ValidationException::class);
    }
    expect(TenantSettings::for($this->shop)->get('day_close.closing_time'))->toBe('21:30');
});

// The closing row

test('the closing row cannot be edited except to reopen it', function () {
    $close = closeRow($this->shop, $this->boss);

    foreach ([
        'counted_cash' => '120.00', 'expected_cash' => '0.00', 'difference' => '0.00', 'note' => 'Nothing to see',
        'totals' => ['count' => 0], 'snapshot_hash' => str_repeat('b', 64), 'day' => '2026-10-22', 'version' => 2,
        'closed_by_user_id' => $this->sara->id, 'closed_at' => now()->addDay(),
    ] as $column => $value) {
        expect(fn () => asTenant($this->shop, fn () => $close->forceFill([$column => $value])->save()))
            ->toThrow(LogicException::class);
        $close = $close->fresh();
    }

    expect($close->counted_cash)->toBe('118.0000')
        ->and($close->note)->toBe('Two riyals short')
        ->and($close->status)->toBe('closed');
});

it('lets the closing row take the status and the three reopening columns, and nothing else with them', function () {
    $close = closeRow($this->shop, $this->boss);

    asTenant($this->shop, fn () => $close->forceFill([
        'status' => 'reopened', 'reopened_by_user_id' => $this->boss->id, 'reopened_at' => now(), 'reopen_reason' => 'A late payment came in',
    ])->save());
    $reopened = $close->fresh();

    expect($reopened->status)->toBe('reopened')
        ->and($reopened->reopened_by_user_id)->toBe($this->boss->id)
        ->and($reopened->reopen_reason)->toBe('A late payment came in')
        ->and($reopened->reopened_at)->not->toBeNull()
        ->and($reopened->expected_cash)->toBe('120.0000')
        ->and(fn () => asTenant($this->shop, fn () => $reopened->forceFill(['status' => 'closed', 'note' => 'Changed'])->save()))->toThrow(LogicException::class);
});

it('never lets the closing row be deleted', function () {
    $close = closeRow($this->shop, $this->boss);

    expect(fn () => asTenant($this->shop, fn () => $close->delete()))->toThrow(LogicException::class);
    expect(asTenant($this->shop, fn () => DayClose::count()))->toBe(1);
});

it('keeps the closing row\'s day as the plain date it was given, and reads back what was written', function () {
    $close = closeRow($this->shop, $this->boss);

    expect($close->day)->toBe('2026-10-21')
        ->and($close->version)->toBe(1)
        ->and($close->totals)->toBe(['by_method' => ['cash' => '120.00'], 'count' => 3, 'per_member' => []])
        ->and(asTenant($this->shop, fn () => DayClose::query()->where('day', '2026-10-21')->count()))->toBe(1);
});

it('shows a closing row only to its own workspace', function () {
    closeRow($this->shop, $this->boss);
    $other = closeShop();

    expect(asTenant($other, fn () => DayClose::count()))->toBe(0)
        ->and(asTenant($this->shop, fn () => DayClose::count()))->toBe(1);
});

it('keeps one closing row per workspace, day and version', function () {
    closeRow($this->shop, $this->boss);
    closeRow($this->shop, $this->boss, ['version' => 2]);
    closeRow(closeShop(), $this->boss);

    // Inside its own savepoint: on PostgreSQL a failed statement poisons the whole surrounding transaction.
    expect(fn () => DB::transaction(fn () => closeRow($this->shop, $this->boss)))->toThrow(QueryException::class);
    expect(asTenant($this->shop, fn () => DayClose::count()))->toBe(2);
})->group('db-errors'); // makes the database raise an error: left out when running against the PGlite stand-in
