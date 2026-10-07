<?php

namespace App\Http\Requests\Concerns;

use App\Support\Money;
use Closure;
use InvalidArgumentException;

/** Validation closures for money fields, shared by every request that takes an amount. */
trait MoneyRules
{
    /** A money amount with at most two decimals (schedules and payments work in whole cents). */
    protected function amountRule(bool $positive = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($positive): void {
            $value = (string) $value;

            if (! preg_match('/^\d{1,14}(\.\d{1,2})?$/', $value) || ($positive && Money::isZero(Money::parse($value)))) {
                $fail($positive
                    ? __('Enter an amount greater than zero, with at most two decimals.')
                    : __('Enter an amount with at most two decimals.'));
            }
        };
    }

    /** The value must be less than another field's amount (a down payment below the price). */
    protected function belowRule(string $otherField, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($otherField, $message): void {
            try {
                $tooHigh = Money::cmp(Money::parse((string) $value), Money::parse((string) $this->input($otherField))) >= 0;
            } catch (InvalidArgumentException) {
                return; // a malformed amount is reported by its own rule
            }

            if ($tooHigh) {
                $fail($message);
            }
        };
    }
}
