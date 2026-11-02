<?php

use App\Support\Countries;

it('names countries in the requested language', function () {
    expect(Countries::options('en')['SA'])->toBe('Saudi Arabia')
        ->and(Countries::options('ar')['SA'])->toBe('المملكة العربية السعودية');
});

it('only offers countries the platform has a currency for', function () {
    expect(array_keys(Countries::options('en')))->toEqualCanonicalizing(array_keys(config('qistas.countries')));
});

it('sorts the list alphabetically in the language it is shown in', function () {
    $names = array_values(Countries::options('en'));
    $sorted = $names;
    sort($sorted);

    expect($names)->toBe($sorted);
});

it('defaults to the application locale', function () {
    app()->setLocale('ar');

    expect(Countries::options()['SA'])->toBe('المملكة العربية السعودية');
});

describe('the name of a time zone', function () {
    it('is said the way the language says it', function () {
        expect(Countries::timeZoneName('Asia/Qatar', 'en'))->toBe('Qatar Time')
            ->and(Countries::timeZoneName('Asia/Qatar', 'ar'))->toContain('قطر')
            ->and(Countries::timeZoneName('Asia/Qatar', 'es'))->toContain('Catar')
            ->and(Countries::timeZoneName('Asia/Riyadh', 'ar'))->not->toBe(Countries::timeZoneName('Asia/Riyadh', 'en'));
    });

    it('defaults to the application locale', function () {
        app()->setLocale('ar');

        expect(Countries::timeZoneName('Asia/Qatar'))->toContain('قطر');
    });

    it('falls back to the zone’s own name, without underscores, when it is not a zone the system knows', function (string $zone, string $shown) {
        expect(Countries::timeZoneName($zone, 'en'))->toBe($shown);
    })->with([
        'unknown place' => ['Mars/Olympus_Mons', 'Mars/Olympus Mons'],
        'not a zone' => ['not a zone', 'not a zone'],
        'empty' => ['', ''],
    ]);
});
