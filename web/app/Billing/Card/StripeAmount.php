<?php

declare(strict_types=1);

namespace App\Billing\Card;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Money as Stripe counts it: in each currency's smallest unit. Most currencies have cents; the zero-decimal ones count
 * whole units; the three-decimal ones count thousandths, and Stripe takes them only in steps of ten (the last digit 0).
 * Never rounds: a price that cannot be charged exactly is refused with the reason.
 */
final class StripeAmount
{
    /** Currencies Stripe counts in whole units. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Currencies Stripe counts in thousandths, whose last digit must be 0. */
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** The smallest charge Stripe accepts, for the currencies the platform is likely to price in. */
    private const MINIMUM = ['USD' => '0.50', 'EUR' => '0.50', 'GBP' => '0.30', 'AED' => '2.00', 'SAR' => '2.00'];

    /** @throws InvalidArgumentException naming why the amount cannot be charged */
    public static function toMinor(string $decimal, string $currency): int
    {
        $currency = self::code($currency);
        if (! preg_match('/^\d+(\.\d+)?$/', $decimal)) {
            throw new InvalidArgumentException(__('The price must be more than zero.'));
        }

        $exponent = self::exponent($currency);
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException($exponent === 0
                ? __(':currency is charged in whole units.', ['currency' => $currency])
                : __(':currency is charged with at most :count decimals.', ['currency' => $currency, 'count' => $exponent]));
        }

        $minor = (int) ltrim($whole.str_pad($fraction, $exponent, '0'), '0');
        if ($minor <= 0) {
            throw new InvalidArgumentException(__('The price must be more than zero.'));
        }
        if ($exponent === 3 && $minor % 10 !== 0) {
            throw new InvalidArgumentException(__('Stripe charges :currency in steps of 0.010: the last decimal must be 0.', ['currency' => $currency]));
        }

        return $minor;
    }

    /** Back to a decimal string at the application's scale (4), as the database keeps money. */
    public static function fromMinor(int $minor, string $currency): string
    {
        $exponent = self::exponent(self::code($currency));

        return Money::div((string) $minor, bcpow('10', (string) $exponent));
    }

    /** Why Stripe could not charge this price, or null when it can. */
    public static function problem(string $decimal, string $currency): ?string
    {
        try {
            self::toMinor($decimal, $currency);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $minimum = self::MINIMUM[strtoupper($currency)] ?? null;
        if ($minimum !== null && Money::cmp($decimal, $minimum) < 0) {
            return __('Stripe does not charge less than :amount :currency.', ['amount' => $minimum, 'currency' => strtoupper($currency)]);
        }

        return null;
    }

    private static function exponent(string $currency): int
    {
        return match (true) {
            in_array($currency, self::ZERO_DECIMAL, true) => 0,
            in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    private static function code(string $currency): string
    {
        $code = strtoupper($currency);
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException(__('A currency is a three-letter code.'));
        }

        return $code;
    }
}
