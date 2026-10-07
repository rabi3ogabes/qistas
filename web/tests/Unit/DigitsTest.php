<?php

use App\Support\Digits;

it('turns Arabic-Indic and Persian digits into ASCII digits', function (string $typed, string $expected) {
    expect(Digits::toAscii($typed))->toBe($expected);
})->with([
    'arabic-indic phone' => ['٠٥٠ ١٢٣ ٤٥٦٧', '050 123 4567'],
    'persian (urdu) digits' => ['۰۳۰۰-۱۲۳۴۵۶۷', '0300-1234567'],
    'mixed with latin' => ['+966 ٥٠ 123 4567', '+966 50 123 4567'],
    'already ascii' => ['+971 50 123 4567', '+971 50 123 4567'],
    'letters and symbols untouched' => ['Ahmad ٣', 'Ahmad 3'],
    'empty' => ['', ''],
]);
