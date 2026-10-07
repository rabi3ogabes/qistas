<?php

namespace App\Support;

use Illuminate\Support\Str;

/** How a customer can pay, named for people. The list itself is config('qistas.payment_methods'). */
final class PaymentMethods
{
    /** @return array<string, string> method => name, in the configured order */
    public static function labels(): array
    {
        $labels = [];

        foreach (config('qistas.payment_methods') as $method) {
            $labels[$method] = self::label($method);
        }

        return $labels;
    }

    public static function label(string $method): string
    {
        return match ($method) {
            'cash' => __('Cash'),
            'bank_transfer' => __('Bank transfer'),
            'card' => __('Card'),
            'cheque' => __('Cheque'),
            'other' => __('Other'),
            default => Str::headline($method),
        };
    }
}
