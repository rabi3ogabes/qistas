<?php

declare(strict_types=1);

use App\Support\Money;

it('parses decimal strings to scale 4', function (string $in, string $out) {
    expect(Money::parse($in))->toBe($out);
})->with([
    ['12', '12.0000'],
    ['12.5', '12.5000'],
    ['0.0001', '0.0001'],
    ['-3.25', '-3.2500'],
    ['1000000.99', '1000000.9900'],
]);

it('rejects anything that is not a plain decimal string with at most 4 decimals', function (mixed $in) {
    Money::parse($in);
})->with([
    'empty' => [''],
    'letters' => ['abc'],
    'exponent' => ['1e3'],
    'five decimals' => ['1.23456'],
    'double sign' => ['--1'],
    'thousand separator' => ['1,234.50'],
    'float' => [1.1],
    'int' => [5],
    'null' => [null],
    'trailing dot' => ['5.'],
])->throws(InvalidArgumentException::class);

it('does exact arithmetic that floats would get wrong', function () {
    expect(Money::add('0.1', '0.2'))->toBe('0.3000')
        ->and(Money::sub('0.3', '0.1'))->toBe('0.2000')
        ->and(Money::mul('19.99', '3'))->toBe('59.9700')
        ->and(Money::div('100', '3'))->toBe('33.3333')
        ->and(Money::add('99999999999.99', '0.01'))->toBe('100000000000.0000');
});

it('rounds half up, away from zero', function (string $in, int $dp, string $out) {
    expect(Money::round($in, $dp))->toBe($out);
})->with([
    ['2.345', 2, '2.35'],
    ['-2.345', 2, '-2.35'],
    ['2.3449', 2, '2.34'],
    ['0.005', 2, '0.01'],
    ['0.0049', 2, '0.00'],
    ['7.50', 2, '7.50'],
    ['2.5', 0, '3'],
]);

it('compares and classifies amounts', function () {
    expect(Money::cmp('1.10', '1.1'))->toBe(0)
        ->and(Money::cmp('2', '10'))->toBe(-1)
        ->and(Money::isZero('0.0000'))->toBeTrue()
        ->and(Money::isPositive('0.0001'))->toBeTrue()
        ->and(Money::isNegative('-0.0001'))->toBeTrue()
        ->and(Money::decimals('12.3400'))->toBe(2)
        ->and(Money::decimals('12.3456'))->toBe(4);
});

describe('reading database aggregates', function () {
    it('keeps an exact decimal string exact', function () {
        expect(Money::fromDatabase('1234567890123.4500'))->toBe('1234567890123.45');
    });

    it('cleans float noise from databases that return floats', function () {
        expect(Money::fromDatabase(33.330000000000005))->toBe('33.33')
            ->and(Money::fromDatabase(100.00000000000001))->toBe('100.00')
            ->and(Money::fromDatabase(0.1 + 0.2))->toBe('0.30');
    });

    it('treats nothing as zero', function () {
        expect(Money::fromDatabase(null))->toBe('0.00')->and(Money::fromDatabase(0))->toBe('0.00');
    });

    it('can keep more decimals', function () {
        expect(Money::fromDatabase('12.3456', 4))->toBe('12.3456');
    });
});
