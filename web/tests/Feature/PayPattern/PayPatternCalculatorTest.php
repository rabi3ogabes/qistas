<?php

use App\Domain\PayPattern\PayPattern;
use App\Domain\PayPattern\PayPatternCalculator;
use App\Entitlements\Feature;
use App\Entitlements\FeatureGroup;
use App\Entitlements\PlatformState;
use App\Models\CustomerPayPattern;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/*
| Daily-habit wave A, H6: the day of the month a customer really pays on, worked out from the dates of their payments.
| The calculator is pure and takes the dates as they fall on the workspace's own calendar (the job that feeds it has
| already moved them out of UTC); only the date part is read. The rules, all fixed so these tests can be exact:
|
|   - the FIRST payment of each calendar month stands for that month (several payments in a month count once);
|   - the last 12 months that have a payment are used; fewer than 3 months say nothing;
|   - each day is min(day, 28), so the 28th to the 31st all mean "the end of the month";
|   - day = the median of those days (an even count takes the LOWER middle, not the mean);
|   - spread = Q3 - Q1 by the nearest-rank method: with the days sorted and n of them, the value at 1-based rank
|     ceil(0.25 n) is Q1 and at rank ceil(0.75 n) is Q3, and the median is at rank ceil(0.5 n);
|   - within = the percent of months whose day is within 3 of the median, rounded half up to a whole number;
|   - high: 4+ months, spread <= 3 and within >= 75; medium: 3+ months, spread <= 5 and within >= 60; else nothing.
|
| Every expected figure below is worked out by hand in the comment above it.
*/

/** The dates, given as 'Y-m-d' (or with a time), as a list of Carbon dates in the order given. @return list<CarbonImmutable> */
function paidOn(string ...$dates): array
{
    return array_map(fn (string $date) => CarbonImmutable::parse($date), array_values($dates));
}

/** What a result says, as one array so a test can compare the whole answer at once. @return array{day: int, spread: int, months: int, within: int, confidence: string}|null */
function patternOf(?PayPattern $pattern): ?array
{
    return $pattern === null ? null : [
        'day' => $pattern->day, 'spread' => $pattern->spread, 'months' => $pattern->months,
        'within' => $pattern->within, 'confidence' => $pattern->confidence,
    ];
}

test('a tight cluster on the 27th is high confidence, day 27', function () {
    // Five months, days in calendar order [25, 27, 27, 28, 26]; sorted [25, 26, 27, 27, 28], n = 5.
    // Median rank ceil(2.5) = 3 -> 27. Q1 rank ceil(1.25) = 2 -> 26. Q3 rank ceil(3.75) = 4 -> 27. Spread 27 - 26 = 1.
    // Within 3 of 27: all five (25 is 2 away) -> 5/5 = 100%. High: 5 months >= 4, spread 1 <= 3, within 100 >= 75.
    $pattern = PayPatternCalculator::calculate(paidOn('2026-01-25', '2026-02-27', '2026-03-27', '2026-04-28', '2026-05-26'));

    expect(patternOf($pattern))->toBe(['day' => 27, 'spread' => 1, 'months' => 5, 'within' => 100, 'confidence' => 'high'])
        ->and($pattern->endOfMonth())->toBeFalse();
});

test('two months say nothing', function () {
    expect(PayPatternCalculator::calculate(paidOn('2026-01-27', '2026-02-27')))->toBeNull()
        ->and(PayPatternCalculator::calculate(paidOn('2026-01-27')))->toBeNull()
        ->and(PayPatternCalculator::calculate([]))->toBeNull();
});

test('three consistent months are medium', function () {
    // Days [26, 27, 28]: n = 3. Median rank ceil(1.5) = 2 -> 27. Q1 rank ceil(0.75) = 1 -> 26. Q3 rank ceil(2.25) = 3 -> 28.
    // Spread 2. Within 3 of 27: all three -> 100%. Three months are never high (it needs 4); medium: 3 >= 3, 2 <= 5, 100 >= 60.
    expect(patternOf(PayPatternCalculator::calculate(paidOn('2026-01-26', '2026-02-27', '2026-03-28'))))
        ->toBe(['day' => 27, 'spread' => 2, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('scattered payments say nothing', function () {
    // Days [3, 11, 19, 27, 8, 22]; sorted [3, 8, 11, 19, 22, 27], n = 6. Q1 rank ceil(1.5) = 2 -> 8, Q3 rank ceil(4.5) = 5 -> 22.
    // Spread 14, far over the 5 that medium allows.
    expect(PayPatternCalculator::calculate(paidOn('2026-01-03', '2026-02-11', '2026-03-19', '2026-04-27', '2026-05-08', '2026-06-22')))->toBeNull();
});

test('payments on the 29th, 30th, 31st and 28th mean the end of the month', function () {
    // 2026 has no 29th of February, so the months are January (31), February (28), March (29), April (30).
    // Each is min(day, 28) = 28: days [28, 28, 28, 28], n = 4, median 28, Q1 = Q3 = 28, spread 0, within 4/4 = 100%.
    $pattern = PayPatternCalculator::calculate(paidOn('2026-01-31', '2026-02-28', '2026-03-29', '2026-04-30'));

    expect(patternOf($pattern))->toBe(['day' => 28, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and($pattern->endOfMonth())->toBeTrue();
});

test('partial early payments count by the first payment in a month', function () {
    // February was paid in two parts, the 27th and (earlier) the 3rd, and the list is not in date order on purpose:
    // the first by date is the 3rd, so February stands for 3. Months and days: Jan 27, Feb 3, Mar 27, Apr 27, May 27.
    // Sorted [3, 27, 27, 27, 27], n = 5. Median rank 3 -> 27. Q1 rank 2 -> 27. Q3 rank 4 -> 27. Spread 0.
    // Within 3 of 27: four of five (the 3 is not) -> 80%. High: 5 >= 4, 0 <= 3, 80 >= 75. Had the 27th stood for
    // February, the answer would have been 100%; that the answer says 80 is what shows the early payment counted.
    $pattern = PayPatternCalculator::calculate(paidOn('2026-02-27', '2026-01-27', '2026-03-27', '2026-02-03', '2026-05-27', '2026-04-27'));

    expect(patternOf($pattern))->toBe(['day' => 27, 'spread' => 0, 'months' => 5, 'within' => 80, 'confidence' => 'high']);
});

test('several payments in one month count once', function () {
    // Three months with two or three payments each: January 27, 28, 29; February 27, 28; March 27. The first of each
    // month stands for it: [27, 27, 27], n = 3, so the answer is 3 months, not 6. Median 27, Q1 = Q3 = 27, spread 0,
    // within 100%. Three months are medium; counted per payment (6) it would have been high.
    expect(patternOf(PayPatternCalculator::calculate(paidOn(
        '2026-01-27', '2026-01-28', '2026-01-29', '2026-02-27', '2026-02-28', '2026-03-27',
    ))))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('one outlier does not move the median', function () {
    // Days [27, 26, 28, 5, 27]; sorted [5, 26, 27, 27, 28], n = 5. Median rank 3 -> 27 (the mean would be
    // (27 + 26 + 28 + 5 + 27) / 5 = 113 / 5 = 22.6, which is no day at all). Q1 rank 2 -> 26. Q3 rank 4 -> 27. Spread 1.
    // Within 3 of 27: 26, 27, 27, 28 are; 5 is 22 away -> 4/5 = 80%. High: 5 >= 4, 1 <= 3, 80 >= 75.
    expect(patternOf(PayPatternCalculator::calculate(paidOn('2026-01-27', '2026-02-26', '2026-03-28', '2026-04-05', '2026-05-27'))))
        ->toBe(['day' => 27, 'spread' => 1, 'months' => 5, 'within' => 80, 'confidence' => 'high']);
});

test('only the last twelve months count', function () {
    // Sixteen months that have a payment: September to December 2024 on the 5th (four months), then January 2025 to
    // January 2026 on the 27th with August 2025 missing (thirteen calendar months, twelve of them paid). The last twelve
    // PAID months are January 2025 to January 2026 less August: the four old months fall away, and so does counting
    // calendar months (that would keep only eleven). The list is handed over newest first, and January 2025 and January
    // 2026 must stay two months, not one.
    // The last twelve: [27 x 12], n = 12, median 27, Q1 = Q3 = 27, spread 0, within 12/12 = 100%.
    // Had all sixteen counted: sorted [5, 5, 5, 5, 27 x 12], n = 16, Q1 rank 4 -> 5, Q3 rank 12 -> 27, spread 22 -> nothing.
    $dates = [];
    for ($month = CarbonImmutable::parse('2024-09-01'); $month->lessThanOrEqualTo(CarbonImmutable::parse('2026-01-01')); $month = $month->addMonth()) {
        if ($month->format('Y-m') === '2025-08') {
            continue;
        }
        $dates[] = $month->setDay($month->lessThan(CarbonImmutable::parse('2025-01-01')) ? 5 : 27);
    }

    expect($dates)->toHaveCount(16)
        ->and(patternOf(PayPatternCalculator::calculate(array_reverse($dates))))
        ->toBe(['day' => 27, 'spread' => 0, 'months' => 12, 'within' => 100, 'confidence' => 'high']);
});

test('an even count takes the lower middle', function () {
    // Six months: 24, 25, 26, 27, then the 31st and the 30th, which are both 28. Sorted [24, 25, 26, 27, 28, 28], n = 6.
    // Median rank ceil(3) = 3 -> 26: the LOWER of the two middle values (26 and 27); the upper would be 27 and the
    // mean 26.5. Q1 rank ceil(1.5) = 2 -> 25. Q3 rank ceil(4.5) = 5 -> 28. Spread 3.
    // Within 3 of 26: 24 (2 away), 25, 26, 27, 28 (2 away), 28 -> 6/6 = 100%. High: 6 >= 4, 3 <= 3, 100 >= 75.
    expect(patternOf(PayPatternCalculator::calculate(paidOn('2026-01-24', '2026-02-25', '2026-03-26', '2026-04-27', '2026-05-31', '2026-06-30'))))
        ->toBe(['day' => 26, 'spread' => 3, 'months' => 6, 'within' => 100, 'confidence' => 'high']);
});

test('the percent within three days is rounded half up', function () {
    // Eight months, days [24, 10, 22, 28, 25, 23, 24, 28]; sorted [10, 22, 23, 24, 24, 25, 28, 28], n = 8.
    // Median rank ceil(4) = 4 -> 24. Q1 rank ceil(2) = 2 -> 22. Q3 rank ceil(6) = 6 -> 25. Spread 3.
    // Within 3 of 24 (21 to 27): 22, 23, 24, 24, 25 are; 10 and the two 28s are not -> 5/8 = 62.5%, which is 63 half up
    // (truncating would say 62). Not high (63 < 75); medium: 8 >= 3, 3 <= 5, 63 >= 60.
    expect(patternOf(PayPatternCalculator::calculate(paidOn(
        '2026-01-24', '2026-02-10', '2026-03-22', '2026-04-28', '2026-05-25', '2026-06-23', '2026-07-24', '2026-08-28',
    ))))->toBe(['day' => 24, 'spread' => 3, 'months' => 8, 'within' => 63, 'confidence' => 'medium']);
});

test('the limits between high, medium and nothing sit where they are documented', function (int $earliest, ?array $expected) {
    // Four months, [earliest, 27, 27, 27]: n = 4, median rank 2 -> 27, Q1 rank 1 -> the earliest day, Q3 rank 3 -> 27, so the
    // spread is 27 less the earliest day. The earliest is within 3 of 27 only when it is 24 or later: 24 -> spread 3 (the most
    // high allows), within 4/4 = 100; 23 -> spread 4, within 3/4 = 75 (medium); 22 -> spread 5 (the most medium allows),
    // within 75; 21 -> spread 6, nothing.
    $pattern = PayPatternCalculator::calculate(paidOn("2026-01-{$earliest}", '2026-02-27', '2026-03-27', '2026-04-27'));

    expect($pattern === null ? null : [$pattern->confidence, $pattern->spread, $pattern->within])->toBe($expected);
})->with([
    'a spread of 3 is still high' => [24, ['high', 3, 100]],
    'a spread of 4 is medium' => [23, ['medium', 4, 75]],
    'a spread of 5 is still medium' => [22, ['medium', 5, 75]],
    'a spread of 6 says nothing' => [21, null],
]);

test('only the date is read, as it falls on the calendar it was given', function () {
    // Just after midnight in Riyadh is still the day before in UTC. The dates are the workspace's own, so the 15th
    // at 00:10 is the 15th, not the 14th, whatever the server's clock is.
    $local = fn (string $date) => CarbonImmutable::parse("{$date} 00:10:00", 'Asia/Riyadh');

    expect(patternOf(PayPatternCalculator::calculate([$local('2026-01-15'), $local('2026-02-15'), $local('2026-03-15'), $local('2026-04-15')])))
        ->toBe(['day' => 15, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high']);
});

// The feature and its table

test('payday learning is a Collecting toggle, off for Free and on for Pro, and ships switched off like every new feature', function () {
    expect(Feature::PaydayLearning->value)->toBe('payday_learning')
        ->and(Feature::PaydayLearning->group())->toBe(FeatureGroup::Collecting)
        ->and(Feature::PaydayLearning->isCore())->toBeFalse()
        ->and(Feature::PaydayLearning->launchState())->toBe(PlatformState::Off)
        ->and(Feature::PaydayLearning->defaultFor('free'))->toBe(['enabled' => false, 'limit' => null])
        ->and(Feature::PaydayLearning->defaultFor('pro'))->toBe(['enabled' => true, 'limit' => null])
        ->and(Feature::PaydayLearning->label())->toBe('Payday learning');
});

test('a customer has one stored pattern, and another workspace never sees it', function () {
    $shop = workspaceOn('pro');
    $other = workspaceOn('pro');
    $ahmad = customerIn($shop, ['name' => 'Ahmad']);
    $row = ['customer_id' => $ahmad->id, 'day' => 27, 'spread' => 1, 'months' => 5, 'within' => 80, 'confidence' => 'high', 'computed_at' => '2026-10-22 08:00:00'];

    $stored = asTenant($shop, fn () => CustomerPayPattern::create($row));

    expect($stored->tenant_id)->toBe($shop->id)
        ->and($stored->day)->toBe(27)
        ->and($stored->within)->toBe(80)
        ->and($stored->confidence)->toBe('high')
        ->and(asTenant($shop, fn () => CustomerPayPattern::query()->count()))->toBe(1)
        ->and(asTenant($other, fn () => CustomerPayPattern::query()->count()))->toBe(0)
        // The one row per customer is the table's own rule, not only the job's habit. Last, because a refused insert
        // ends the transaction a PostgreSQL test runs in.
        ->and(fn () => asTenant($shop, fn () => CustomerPayPattern::create($row)))->toThrow(UniqueConstraintViolationException::class);
});
