<?php

namespace App\Domain\Schedule;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A discount at sale (Win Plan PP6): a fixed amount or a percent of the price, rounded half up to the cent, taken off
 * the price before the down payment. The schedule is then built on what is left (the net price).
 */
final class Discount
{
    /**
     * @param  'none'|'fixed'|'percent'|string  $type
     *
     * @throws ValidationException when it is not a discount the price allows
     */
    public static function amount(string $principal, string $type, ?string $value): string
    {
        if ($type === 'none' || $value === null || $value === '') {
            return '0.00';
        }

        try {
            $principal = Money::parse($principal);
            $value = Money::parse($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['discount_value' => __('Enter the discount as an amount or a percent.')]);
        }

        $amount = match ($type) {
            'fixed' => Money::add($value, '0', 2),
            'percent' => Money::round(Money::div(Money::mul($principal, $value, 6), '100', 6), 2),
            default => throw ValidationException::withMessages(['discount_type' => __('The discount is a fixed amount or a percent.')]),
        };

        if (Money::isNegative($amount) || Money::cmp($amount, $principal) >= 0) {
            throw ValidationException::withMessages(['discount_value' => __('The discount must be less than the price.')]);
        }

        return $amount;
    }

    /** The price once the discount is taken off. */
    public static function net(string $principal, string $discount): string
    {
        return Money::sub(Money::parse($principal), $discount, 2);
    }

    /** The tax already in a price (a price with tax at :percent includes price x percent / (100 + percent)). */
    public static function taxIn(string $price, ?string $percent): ?string
    {
        if ($percent === null || $percent === '' || Money::isZero(Money::parse($percent))) {
            return null;
        }

        return Money::round(Money::div(Money::mul($price, $percent, 6), Money::add('100', $percent, 4), 6), 2);
    }
}
