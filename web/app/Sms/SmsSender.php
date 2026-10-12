<?php

namespace App\Sms;

/**
 * Sends one text message (Sprint 3, decision D6). No provider is chosen yet (the owner's choice of 10 Oct 2026: decide
 * later), so text messages are not offered anywhere; a provider's driver plugs in here, and `available()` turns them on.
 */
interface SmsSender
{
    /** Whether a provider is set up, so text messages can be offered at all. */
    public function available(): bool;

    /** @param  string  $to  a phone number with its country code */
    public function send(string $to, string $text): bool;
}
