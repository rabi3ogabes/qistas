<?php

namespace App\Sms;

use Illuminate\Support\Facades\Log;

/** The stand-in until an SMS provider is chosen: text messages are not offered, and one asked for is only logged. */
final class NoSmsProvider implements SmsSender
{
    public function available(): bool
    {
        return false;
    }

    public function send(string $to, string $text): bool
    {
        Log::info('sms not sent: no provider', ['length' => mb_strlen($text)]);

        return false;
    }
}
