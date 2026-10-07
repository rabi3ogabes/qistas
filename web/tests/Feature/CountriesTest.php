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
