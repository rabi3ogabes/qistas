<?php

declare(strict_types=1);

use App\Domain\Schedule\InvalidScheduleException;
use App\Domain\Schedule\ScheduleGenerator;
use App\Domain\Schedule\ScheduleRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;

function scheduleVectors(): array
{
    // shared/schedule-vectors.json is produced by an independent Decimal reference implementation.
    $path = dirname(__DIR__, 3).'/shared/schedule-vectors.json';

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

dataset('valid vectors', fn () => collect(scheduleVectors()['valid'])->mapWithKeys(fn ($v) => [$v['name'] => [$v]])->all());
dataset('invalid vectors', fn () => collect(scheduleVectors()['invalid'])->mapWithKeys(fn ($v) => [$v['name'] => [$v]])->all());

it('reproduces the reference schedule exactly', function (array $vector) {
    $result = (new ScheduleGenerator)->generate(ScheduleRequest::fromArray($vector['input']));

    expect($result->toArray())->toBe($vector['expected']);
})->with('valid vectors');

it('rejects invalid requests', function (array $vector) {
    (new ScheduleGenerator)->generate(ScheduleRequest::fromArray($vector['input']));
})->with('invalid vectors')->throws(InvalidScheduleException::class);

it('always sums to the total, never creates a zero instalment and keeps dates ascending', function () {
    mt_srand(20261007);
    $generator = new ScheduleGenerator;
    $frequencies = ['weekly', 'biweekly', 'monthly'];

    for ($i = 0; $i < 300; $i++) {
        $principal = number_format(mt_rand(1, 5_000_000) / 100, 2, '.', '');
        $count = mt_rand(1, 60);
        if (Money::cmp(Money::mul($principal, '100'), (string) $count) < 0) {
            continue; // too small for one cent each: rejected elsewhere
        }
        $request = new ScheduleRequest(
            principal: $principal,
            downPayment: '0.00',
            markupType: ['none', 'percent', 'fixed'][mt_rand(0, 2)],
            markupValue: (string) mt_rand(0, 25),
            count: $count,
            frequency: $frequencies[mt_rand(0, 2)],
            firstDueDate: CarbonImmutable::create(2026, 1, 1)->addDays(mt_rand(0, 900))->format('Y-m-d'),
        );

        $result = $generator->generate($request);
        $sum = array_reduce($result->installments, fn (string $c, array $r) => Money::add($c, $r['amount']), '0');

        expect(Money::round($sum, 2))->toBe($result->total)
            ->and(count($result->installments))->toBe($count);
        foreach ($result->installments as $k => $row) {
            expect(Money::cmp($row['amount'], '0'))->toBe(1);
            if ($k > 0) {
                expect($row['due_date'] > $result->installments[$k - 1]['due_date'])->toBeTrue();
            }
        }
    }
});
