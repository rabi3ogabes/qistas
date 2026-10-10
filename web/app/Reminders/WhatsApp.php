<?php

namespace App\Reminders;

/**
 * A phone number as WhatsApp's wa.me links want it: digits only, with the country code in front. The app does the same
 * in app/lib/features/reminders/reminders.dart (`whatsappNumber`); keep the two in step.
 */
final class WhatsApp
{
    public const DIAL_CODES = [
        'SA' => '966', 'AE' => '971', 'QA' => '974', 'KW' => '965', 'BH' => '973', 'OM' => '968', 'EG' => '20', 'JO' => '962',
        'LB' => '961', 'IQ' => '964', 'MA' => '212', 'DZ' => '213', 'TN' => '216', 'LY' => '218', 'SD' => '249', 'YE' => '967',
        'SY' => '963', 'MR' => '222', 'TR' => '90', 'PK' => '92', 'IN' => '91', 'BD' => '880', 'MY' => '60', 'ID' => '62',
        'FR' => '33', 'ES' => '34', 'DE' => '49', 'IT' => '39', 'GB' => '44', 'US' => '1', 'CA' => '1', 'AU' => '61',
        'NG' => '234', 'KE' => '254', 'ZA' => '27',
    ];

    /** Null when the number is too short to be one. */
    public static function number(string $phone, string $country): ?string
    {
        $trimmed = trim($phone);
        $digits = (string) preg_replace('/\D/', '', $trimmed);
        if (strlen($digits) < 7) {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            return $digits;
        }
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        $code = self::DIAL_CODES[strtoupper($country)] ?? null;
        if ($code !== null && str_starts_with($digits, '0')) {
            return $code.substr($digits, 1);
        }

        return $digits;
    }
}
