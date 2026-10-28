<?php

use App\Actions\CloseDay;
use App\Actions\VoidTransaction;
use App\Entitlements\Feature;
use App\Entitlements\FeatureUnavailable;
use App\Models\AuditLog;
use App\Models\DayClose;
use App\Support\WorkspaceDay;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
| Daily-habit wave A, H4: closing a day sets the cash a person counted against what Qistas recorded for the workspace's
| day (DayTotals), and keeps the figures as they were, signed with a hash. Anyone who records payments may close; only
| an owner or a manager may reopen, with a reason, and closing the day again writes the next version. Nothing in the
| ledger is ever edited.
|
| The workspace is in Riyadh (UTC+3, no daylight saving), so 21:00 UTC is midnight there. The clock stands at noon UTC on
| the 21st (15:00 in Riyadh) unless a test moves it.
*/

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
    $this->shop = closeShop();
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->omar = closeStaff($this->shop, 'Omar Collector');
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->mona = closeStaff($this->shop, 'Mona Manager', 'manager');
    $this->contract = closeContract($this->shop);
    $this->travelTo('2026-10-21 12:00:00');
});

it('closes today with counted cash and records the difference', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    closeTake($this->contract, '20.00', by: $this->omar);
    closeTake($this->contract, '50.00', 'card', by: $this->omar);
    $this->travelTo('2026-10-21 17:05:00'); // 20:05 in Riyadh

    $close = app(CloseDay::class)->handle($this->shop, WorkspaceDay::of($this->shop), '115.50', 'Counted twice', $this->sara);
    $stored = asTenant($this->shop, fn () => DayClose::query()->findOrFail($close->id));

    expect($stored->day)->toBe('2026-10-21')
        ->and($stored->version)->toBe(1)
        ->and($stored->status)->toBe('closed')
        ->and($stored->expected_cash)->toBe('120.0000')
        ->and($stored->counted_cash)->toBe('115.5000')
        ->and($stored->difference)->toBe('-4.5000')
        ->and($stored->totals)->toBe([
            'by_method' => ['cash' => '120.00', 'card' => '50.00'],
            'count' => 3,
            'per_member' => [
                $this->omar->id => ['name' => 'Omar Collector', 'cash' => '20.00', 'count' => 1],
                $this->sara->id => ['name' => 'Sara Collector', 'cash' => '100.00', 'count' => 1],
            ],
        ])
        ->and($stored->note)->toBe('Counted twice')
        ->and($stored->closed_by_user_id)->toBe($this->sara->id)
        ->and($stored->closed_at->toDateTimeString())->toBe('2026-10-21 17:05:00')
        ->and($stored->snapshot_hash)->toMatch('/^[0-9a-f]{64}$/')
        // The hash can be worked out again from the stored row, so a later reading can tell whether it was touched.
        ->and(CloseDay::snapshotHash($stored->day, $stored->version, $stored->expected_cash, $stored->counted_cash, $stored->totals))->toBe($stored->snapshot_hash);

    // Over is positive: the 20th took no cash, and 5.00 was found in the till.
    $over = closeDay($this->shop, '2026-10-20', $this->sara, '5.00');
    expect($over->fresh()->difference)->toBe('5.0000');
});

it('refuses a second close of the same day', function () {
    $first = closeDay($this->shop, '2026-10-21', $this->sara, '0.00', 'Quiet day');

    expect(validationErrors(fn () => closeDay($this->shop, '2026-10-21', $this->boss, '10.00')))
        ->toBe(['day' => [__('This day is already closed.')]]);

    $closes = asTenant($this->shop, fn () => DayClose::query()->get());
    expect($closes)->toHaveCount(1)
        ->and($closes->first()->id)->toBe($first->id)
        ->and($closes->first()->counted_cash)->toBe('0.0000');
});

test('two simultaneous closes make one', function () {
    // Two phones close the day together. On PostgreSQL the second waits on the day's row lock; when the day has no row
    // yet there is nothing to lock, and the unique key (tenant, day, version) decides. SQLite has one connection and no
    // row locks, so the other phone is played by a close that lands after this one has looked at the day and before it
    // writes: its write is refused by the key, and the person is told the day is closed, never shown a database error.
    $other = null;
    DayClose::creating(function () use (&$other): void {
        if ($other === null) {
            $other = 'closing';
            $other = closeDay($this->shop, '2026-10-21', $this->omar, '100.00');
        }
    });

    $errors = validationErrors(fn () => closeDay($this->shop, '2026-10-21', $this->sara, '100.00'));

    expect($errors)->toBe(['day' => [__('This day is already closed.')]])
        ->and($other)->toBeInstanceOf(DayClose::class)
        ->and($other->version)->toBe(1)
        ->and($other->closed_by_user_id)->toBe($this->omar->id);
})->group('db-errors'); // makes the database raise an error: left out when running against the PGlite stand-in

it('refuses a future day and one more than seven days back', function () {
    expect(validationErrors(fn () => closeDay($this->shop, '2026-10-22', $this->sara)))
        ->toBe(['day' => [__('A day that has not come yet cannot be closed.')]])
        ->and(validationErrors(fn () => closeDay($this->shop, '2026-10-13', $this->sara)))
        ->toBe(['day' => [__('Only today and the seven days before it can be closed.')]]);

    // The seventh day back is the last that can still be closed.
    expect(closeDay($this->shop, '2026-10-14', $this->sara)->day)->toBe('2026-10-14');

    // "Today" is the workspace's: at 00:30 in Riyadh the 22nd has begun, though it is still the 21st in UTC.
    $this->travelTo('2026-10-21 21:30:00');
    expect(closeDay($this->shop, '2026-10-22', $this->sara)->day)->toBe('2026-10-22')
        ->and(validationErrors(fn () => closeDay($this->shop, '2026-10-23', $this->sara)))->toHaveKey('day');

    expect(asTenant($this->shop, fn () => DayClose::query()->pluck('day')->sort()->values()->all()))->toBe(['2026-10-14', '2026-10-22']);
});

it('rejects a negative or three-decimal amount', function () {
    foreach ([
        '-0.01' => __('The counted cash cannot be negative.'),
        '12.345' => __('The amount can have at most two decimals.'),
        '12.34567' => __('The amount can have at most two decimals.'),
        '12.500' => __('The amount can have at most two decimals.'),
        'twelve' => __('Enter a valid amount.'),
        '' => __('Enter a valid amount.'),
        // More than the fourteen digits before the point that the column holds (PostgreSQL would refuse the row).
        '100000000000000' => __('Enter a valid amount.'),
        '1000000000000000000' => __('Enter a valid amount.'),
        '1e18' => __('Enter a valid amount.'),
        '1e5' => __('Enter a valid amount.'),
        ' 12' => __('Enter a valid amount.'),
        '12.' => __('Enter a valid amount.'),
        '.5' => __('Enter a valid amount.'),
        // `$` would accept a trailing newline and bcadd would then throw: the pattern ends with \z.
        "12\n" => __('Enter a valid amount.'),
    ] as $amount => $message) {
        expect(validationErrors(fn () => closeDay($this->shop, '2026-10-21', $this->sara, (string) $amount)))
            ->toBe(['counted_cash' => [$message]], "counted cash [{$amount}]");
    }

    expect(asTenant($this->shop, fn () => DayClose::count()))->toBe(0)
        ->and(closeDay($this->shop, '2026-10-21', $this->sara, '12.5')->fresh()->counted_cash)->toBe('12.5000');
});

it('keeps every figure within what the columns hold, the difference included', function () {
    // Fourteen digits before the point is the most the columns hold, and it is accepted on a day that took nothing. (What
    // is written, not what SQLite reads back: it keeps a number this long as a float; PostgreSQL keeps it exactly.)
    $largest = closeDay($this->shop, '2026-10-20', $this->sara, '99999999999999.99');
    expect($largest->counted_cash)->toBe('99999999999999.9900')
        ->and($largest->difference)->toBe('99999999999999.9900');

    // Today only gave money back, so its expected cash is negative and the same count would make a fifteen-digit
    // difference: refused like an amount that is too large, and nothing is written.
    $this->travelTo('2026-10-19 09:00:00');
    $earlier = closeTake($this->contract, '100.00', by: $this->sara);
    $this->travelTo('2026-10-21 12:00:00');
    app(VoidTransaction::class)->handle($earlier, 'Refunded', $this->boss);

    expect(closeTotals($this->shop)['expected_cash'])->toBe('-100.00')
        ->and(validationErrors(fn () => closeDay($this->shop, '2026-10-21', $this->sara, '99999999999999.99')))
        ->toBe(['counted_cash' => [__('Enter a valid amount.')]])
        ->and(asTenant($this->shop, fn () => DayClose::query()->where('day', '2026-10-21')->count()))->toBe(0)
        ->and(closeDay($this->shop, '2026-10-21', $this->sara, '0.00')->fresh()->difference)->toBe('100.0000');
});

it('refuses a day cut on another workspace\'s clock', function () {
    // A programming mistake, not something a person typed: the day's range would be cut in the wrong zone.
    $newYorkDay = WorkspaceDay::on(closeShop('US', 'America/New_York'), '2026-10-21');

    expect(fn () => app(CloseDay::class)->handle($this->shop, $newYorkDay, '0.00', null, $this->sara))->toThrow(InvalidArgumentException::class)
        ->and(asTenant($this->shop, fn () => DayClose::count()))->toBe(0);
});

test('a collector may close, a viewer may not', function () {
    $viewer = closeStaff($this->shop, 'Vera Viewer', 'viewer');
    $stranger = closeStaff(closeShop(), 'Owner Elsewhere', 'owner');

    // Who may close is asked first: a viewer is refused even when what they typed is wrong too.
    expect(fn () => closeDay($this->shop, '2026-10-21', $viewer, '-1'))->toThrow(AuthorizationException::class)
        ->and(fn () => closeDay($this->shop, '2026-10-21', $stranger))->toThrow(AuthorizationException::class)
        ->and(asTenant($this->shop, fn () => DayClose::count()))->toBe(0);

    $accountant = closeStaff($this->shop, 'Adel Accountant', 'accountant');
    expect(closeDay($this->shop, '2026-10-21', $this->sara)->closed_by_user_id)->toBe($this->sara->id)
        ->and(closeDay($this->shop, '2026-10-20', $accountant)->closed_by_user_id)->toBe($accountant->id);

    // The team reads the closes, viewers included.
    asTenant($this->shop, function () use ($viewer): void {
        expect(Gate::forUser($viewer)->allows('viewAny', DayClose::class))->toBeTrue()
            ->and(Gate::forUser($viewer)->allows('view', DayClose::query()->firstOrFail()))->toBeTrue()
            ->and(Gate::forUser($viewer)->allows('create', DayClose::class))->toBeFalse();
    });
});

test('only an owner or manager may reopen, with a reason', function () {
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
    $accountant = closeStaff($this->shop, 'Adel Accountant', 'accountant');
    $managerElsewhere = closeStaff(closeShop(), 'Manager Elsewhere', 'manager');

    foreach ([$this->sara, $accountant, $managerElsewhere] as $who) {
        expect(fn () => reopenDay($close, 'A late payment came in', $who))->toThrow(AuthorizationException::class);
    }
    expect(validationErrors(fn () => reopenDay($close, 'late', $this->boss)))->toBe(['reason' => [__('Give a reason of at least :min characters.', ['min' => 5])]])
        ->and(validationErrors(fn () => reopenDay($close, '   ab    ', $this->boss)))->toHaveKey('reason')
        ->and($close->fresh()->status)->toBe('closed');

    $this->travelTo('2026-10-21 18:00:00');
    $reopened = reopenDay($close, '  A late payment came in ', $this->mona)->fresh();

    expect($reopened->status)->toBe('reopened')
        ->and($reopened->reopened_by_user_id)->toBe($this->mona->id)
        ->and($reopened->reopened_at->toDateTimeString())->toBe('2026-10-21 18:00:00')
        ->and($reopened->reopen_reason)->toBe('A late payment came in')
        ->and($reopened->counted_cash)->toBe('0.0000');

    // Reopening is one way: a close that is already reopened cannot be reopened again.
    expect(validationErrors(fn () => reopenDay($reopened, 'Once more, please', $this->boss)))
        ->toBe(['day' => [__('Only a day that is closed can be reopened.')]]);
});

test('a reopened day can be closed again as version 2', function () {
    closeTake($this->contract, '100.00', by: $this->sara);
    $first = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
    reopenDay($first, 'A late payment came in', $this->boss);

    closeTake($this->contract, '30.00', by: $this->omar);
    $second = closeDay($this->shop, '2026-10-21', $this->omar, '125.00')->fresh();

    expect($second->version)->toBe(2)
        ->and($second->status)->toBe('closed')
        ->and($second->expected_cash)->toBe('130.0000')
        ->and($second->difference)->toBe('-5.0000')
        ->and($second->snapshot_hash)->not->toBe($first->snapshot_hash)
        ->and($first->fresh()->status)->toBe('reopened')
        ->and($first->fresh()->expected_cash)->toBe('100.0000');

    // Only the day's standing close can be reopened; the first version stays as it was signed.
    expect(validationErrors(fn () => reopenDay($first->fresh(), 'Reopen the old one', $this->boss)))->toHaveKey('day')
        ->and(reopenDay($second, 'Another late payment', $this->boss)->status)->toBe('reopened')
        ->and(closeDay($this->shop, '2026-10-21', $this->sara, '130.00')->version)->toBe(3);
});

test('the hash changes when any figure changes', function () {
    $totals = [
        'by_method' => ['cash' => '120.00', 'card' => '50.00'],
        'count' => 3,
        'per_member' => [
            'member-a' => ['name' => 'Omar Collector', 'cash' => '20.00', 'count' => 1],
            'member-b' => ['name' => 'Sara Collector', 'cash' => '100.00', 'count' => 2],
        ],
    ];
    $hash = fn (array $changes = [], array $figures = []) => CloseDay::snapshotHash(
        $changes['day'] ?? '2026-10-21', $changes['version'] ?? 1, $changes['expected'] ?? '120.00', $changes['counted'] ?? '118.00', $figures + $totals,
    );
    $member = fn (string $id, array $member) => ['per_member' => array_replace($totals['per_member'], [$id => $member])];
    $signed = $hash();

    $changed = [
        'the day' => $hash(['day' => '2026-10-20']),
        'the version' => $hash(['version' => 2]),
        'the expected cash' => $hash(['expected' => '120.01']),
        'the counted cash' => $hash(['counted' => '118.01']),
        'the fourth decimal of the counted cash' => $hash(['counted' => '118.0001']),
        'the fourth decimal of a method\'s total' => $hash(figures: ['by_method' => ['cash' => '120.0001', 'card' => '50.00']]),
        'a method\'s total' => $hash(figures: ['by_method' => ['cash' => '120.00', 'card' => '50.01']]),
        'another method' => $hash(figures: ['by_method' => ['cash' => '120.00', 'card' => '50.00', 'bank_transfer' => '0.00']]),
        'the count' => $hash(figures: ['count' => 4]),
        'a member\'s cash' => $hash(figures: $member('member-a', ['name' => 'Omar Collector', 'cash' => '20.01', 'count' => 1])),
        'a member\'s count' => $hash(figures: $member('member-a', ['name' => 'Omar Collector', 'cash' => '20.00', 'count' => 2])),
        'who took the cash' => $hash(figures: ['per_member' => [
            'member-a' => ['name' => 'Omar Collector', 'cash' => '100.00', 'count' => 2],
            'member-b' => ['name' => 'Sara Collector', 'cash' => '20.00', 'count' => 1],
        ]]),
    ];
    foreach ($changed as $what => $other) {
        expect($other)->not->toBe($signed, "changing {$what} must change the hash");
    }
    expect(array_unique(array_values($changed)))->toHaveCount(count($changed));

    // What is not a figure leaves it alone: the members' names, the order things are listed in, and trailing zeros.
    expect($hash(figures: [
        'per_member' => [
            'member-b' => ['name' => 'سارة', 'cash' => '100.0000', 'count' => 2],
            'member-a' => ['name' => 'Someone no longer on the team', 'cash' => '20.0000', 'count' => 1],
        ],
        'by_method' => ['card' => '50.0000', 'cash' => '120.0000'],
    ], changes: ['expected' => '120.0000', 'counted' => '118.0000']))->toBe($signed);
});

test('a change in the fourth decimal of a stored close no longer matches its hash', function () {
    closeTake($this->contract, '120.00', by: $this->sara);
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '118.00');
    $recomputed = function () use ($close): string {
        $row = $close->fresh();

        return CloseDay::snapshotHash($row->day, $row->version, $row->expected_cash, $row->counted_cash, $row->totals);
    };
    expect($recomputed())->toBe($close->snapshot_hash);

    // Straight to the table, past the model's refusal to edit a close: the columns keep four decimals.
    DB::table('day_closes')->where('id', $close->id)->update(['counted_cash' => '118.0001']);
    expect($recomputed())->not->toBe($close->snapshot_hash);

    DB::table('day_closes')->where('id', $close->id)->update(['counted_cash' => '118.0000', 'expected_cash' => '120.0001']);
    expect($recomputed())->not->toBe($close->snapshot_hash);

    DB::table('day_closes')->where('id', $close->id)->update(['expected_cash' => '120.0000']);
    expect($recomputed())->toBe($close->snapshot_hash);
});

test('the hash is the same whichever language the day is closed in', function () {
    closeTake($this->contract, '80.00', by: $this->sara);
    closeTake($this->contract, '20.00', by: $this->omar);
    // Sara leaves the team: her line is now named in the language of whoever closes the day.
    $this->sara->delete();

    $closeIn = function (string $locale): DayClose {
        app()->setLocale($locale);
        DB::beginTransaction();
        try {
            return closeDay($this->shop, '2026-10-21', $this->omar, '100.00');
        } finally {
            DB::rollBack();
        }
    };
    $english = $closeIn('en');
    $arabic = $closeIn('ar');

    expect($english->totals['per_member']['unknown']['name'])->toBe('Someone no longer on the team')
        ->and($arabic->totals['per_member']['unknown']['name'])->toBe('شخص لم يعد ضمن الفريق')
        ->and($arabic->version)->toBe($english->version)
        ->and($arabic->snapshot_hash)->toBe($english->snapshot_hash);
});

it('writes audit rows', function () {
    closeTake($this->contract, '120.00', by: $this->sara);
    $close = closeDay($this->shop, '2026-10-21', $this->sara, '118.00', 'Two riyals short, Ahmad Ali paid in coins');
    $this->travelTo('2026-10-21 18:00:00');
    reopenDay($close, 'Ahmad Ali came back with the two riyals', $this->boss);

    // Figures only: the note and the reason stay on the close, so no customer's name reaches the audit trail.
    expect(closeAudits($this->shop->id))->toBe([
        ['action' => 'day_close.closed', 'subject_id' => $close->id, 'user_id' => $this->sara->id, 'changes' => [
            'day' => '2026-10-21', 'version' => 1, 'expected_cash' => '120.00', 'counted_cash' => '118.00', 'difference' => '-2.00',
        ]],
        ['action' => 'day_close.reopened', 'subject_id' => $close->id, 'user_id' => $this->boss->id, 'changes' => ['day' => '2026-10-21', 'version' => 1]],
    ])->and(AuditLog::query()->where('subject_id', $close->id)->pluck('subject_type')->unique()->all())->toBe([(new DayClose)->getMorphClass()]);
});

it('closes and reopens nothing while the feature is off', function () {
    $close = closeDay($this->shop, '2026-10-21', $this->sara);
    switchOff(Feature::DayClose);

    expect(fn () => closeDay($this->shop, '2026-10-20', $this->sara))->toThrow(FeatureUnavailable::class)
        ->and(fn () => reopenDay($close, 'A late payment came in', $this->boss))->toThrow(FeatureUnavailable::class)
        ->and($close->fresh()->status)->toBe('closed')
        ->and(asTenant($this->shop, fn () => DayClose::count()))->toBe(1);
});
