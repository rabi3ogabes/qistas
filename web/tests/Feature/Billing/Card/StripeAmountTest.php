<?php

declare(strict_types=1);

use App\Billing\Card\StripeAmount;

/*
| Stripe counts money in each currency's smallest unit: cents for USD, whole yen for JPY, and for the three-decimal
| currencies (KWD, BHD...) thousandths whose last digit must be 0. A price that cannot be charged exactly is refused
| with a reason, never rounded.
*/

it('converts to the smallest unit', function (string $decimal, string $currency, int $minor) {
    expect(StripeAmount::toMinor($decimal, $currency))->toBe($minor);
})->with([
    'USD' => ['12.00', 'USD', 1200],
    'USD with four decimals from the database' => ['120.0000', 'USD', 12000],
    'SAR' => ['19.99', 'SAR', 1999],
    'lowercase code' => ['12.5', 'usd', 1250],
    'KWD' => ['12.340', 'KWD', 12340],
    'KWD from the database' => ['1.5000', 'KWD', 1500],
    'JPY' => ['1500', 'JPY', 1500],
    'JPY with zero decimals' => ['1500.0000', 'JPY', 1500],
]);

it('refuses what cannot be charged exactly', function (string $decimal, string $currency) {
    expect(fn () => StripeAmount::toMinor($decimal, $currency))->toThrow(InvalidArgumentException::class);
})->with([
    'a third decimal in USD' => ['12.345', 'USD'],
    'a last digit in KWD' => ['12.345', 'KWD'],
    'a fraction of a yen' => ['1500.50', 'JPY'],
    'zero' => ['0.00', 'USD'],
    'negative' => ['-5.00', 'USD'],
    'not a number' => ['twelve', 'USD'],
    'not a currency code' => ['12.00', 'DOLLARS'],
]);

it('converts back to a four-decimal string', function (int $minor, string $currency, string $decimal) {
    expect(StripeAmount::fromMinor($minor, $currency))->toBe($decimal);
})->with([
    [1200, 'USD', '12.0000'],
    [12340, 'KWD', '12.3400'],
    [1500, 'JPY', '1500.0000'],
    [0, 'USD', '0.0000'],
]);

it('names the problem with a price, or none', function (string $decimal, string $currency, bool $problem) {
    expect(StripeAmount::problem($decimal, $currency) !== null)->toBe($problem);
})->with([
    'fine' => ['12.00', 'USD', false],
    'below the USD minimum' => ['0.40', 'USD', true],
    'at the USD minimum' => ['0.50', 'USD', false],
    'below the AED minimum' => ['1.50', 'AED', true],
    'below the GBP minimum' => ['0.20', 'GBP', true],
    'a last digit in KWD' => ['12.345', 'KWD', true],
    'a currency with no known minimum' => ['0.10', 'QAR', false],
]);
