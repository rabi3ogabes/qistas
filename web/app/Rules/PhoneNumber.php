<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** 6 to 15 digits (the E.164 maximum), with the usual separators and an optional leading +. */
final class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $digits = preg_match_all('/\d/', $value);

        if (! preg_match('/^\+?[\d\s().\-]+$/', $value) || $digits < 6 || $digits > 15 || strlen($value) > 32) {
            $fail(__('Enter a valid phone number.'));
        }
    }
}
