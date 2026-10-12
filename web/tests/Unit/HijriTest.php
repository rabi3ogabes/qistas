<?php

declare(strict_types=1);

use App\Support\Hijri;
use App\Support\HijriTable;
use Carbon\CarbonImmutable;

/*
| The Umm al-Qura calendar (Sprint 2 Task 2, Win Plan CT26). shared/hijri-vectors.json is generated from ICU by
| shared/tools/generate_hijri_vectors.php; the app is checked against the same file, so the website, the PDFs and the
| app agree to the day.
*/

function hijriVectors(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/shared/hijri-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
}

dataset('hijri samples', fn () => collect(hijriVectors()['samples'])->mapWithKeys(fn ($s) => [$s['gregorian'] => [$s]])->all());

it('holds the generated month lengths', function () {
    $vectors = hijriVectors();

    expect(HijriTable::FIRST_YEAR)->toBe($vectors['first_year'])
        ->and(HijriTable::LAST_YEAR)->toBe($vectors['last_year'])
        ->and(HijriTable::EPOCH)->toBe($vectors['epoch'])
        ->and(HijriTable::YEARS)->toBe(array_values($vectors['months']));
});

it('converts each sample both ways', function (array $sample) {
    [$year, $month, $day] = $sample['hijri'];

    expect(Hijri::fromGregorian($sample['gregorian']))->toBe(['year' => $year, 'month' => $month, 'day' => $day])
        ->and(Hijri::toGregorian($year, $month, $day)?->format('Y-m-d'))->toBe($sample['gregorian']);
})->with('hijri samples');

it('walks every day of the table without a gap or a repeat', function () {
    $day = CarbonImmutable::parse(HijriTable::EPOCH);
    $previous = null;

    while (($hijri = Hijri::fromGregorian($day)) !== null) {
        if ($previous !== null) {
            $next = Hijri::monthLength($previous['year'], $previous['month']) >= $previous['day'] + 1
                ? [...$previous, 'day' => $previous['day'] + 1]
                : ($previous['month'] < 12 ? ['year' => $previous['year'], 'month' => $previous['month'] + 1, 'day' => 1] : ['year' => $previous['year'] + 1, 'month' => 1, 'day' => 1]);
            expect($hijri)->toBe($next, $day->format('Y-m-d'));
        }
        $previous = $hijri;
        $day = $day->addDay();
    }

    expect($previous)->toBe(['year' => HijriTable::LAST_YEAR, 'month' => 12, 'day' => Hijri::monthLength(HijriTable::LAST_YEAR, 12)])
        ->and($day->format('Y-m-d'))->toBe('2077-11-17');
});

it('knows nothing outside the table', function () {
    expect(Hijri::fromGregorian('1937-03-13'))->toBeNull()
        ->and(Hijri::fromGregorian('2077-11-17'))->toBeNull()
        ->and(Hijri::toGregorian(HijriTable::FIRST_YEAR - 1, 12, 29))->toBeNull()
        ->and(Hijri::toGregorian(HijriTable::LAST_YEAR + 1, 1, 1))->toBeNull();
});

it('refuses a day or a month that does not exist', function () {
    $short = strpos(HijriTable::YEARS[1447 - HijriTable::FIRST_YEAR], '0') + 1;

    expect(Hijri::monthLength(1447, $short))->toBe(29)
        ->and(Hijri::toGregorian(1447, $short, 29))->not->toBeNull()
        ->and(Hijri::toGregorian(1447, $short, 30))->toBeNull()
        ->and(Hijri::toGregorian(1447, 13, 1))->toBeNull()
        ->and(Hijri::toGregorian(1447, 1, 0))->toBeNull();
});

it('reads a typed Hijri date, year first or day first, in any digits', function (string $typed) {
    expect(Hijri::parse($typed)?->format('Y-m-d'))->toBe('2026-02-01');
})->with(['1447-08-13', '1447/8/13', '13/08/1447', '13-8-1447', ' 13.08.1447 ', '١٣/٠٨/١٤٤٧', '۱۴۴۷-۰۸-۱۳']);

it('does not read what is not a Hijri date', function (string $typed) {
    expect(Hijri::parse($typed))->toBeNull();
})->with(['', 'soon', '1447-13-01', '1447-08-32', '13/08/47', '2026-02-01', '1447-08', '1/2/3/1447']);
