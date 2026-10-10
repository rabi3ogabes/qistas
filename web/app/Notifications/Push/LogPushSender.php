<?php

namespace App\Notifications\Push;

use App\Models\PushToken;
use Illuminate\Support\Facades\Log;

/**
 * Until the owner gives the Firebase key, a push is only written to the log (its kind and title, never a token in full).
 * The inbox still has every alert.
 */
final class LogPushSender implements PushSender
{
    public function send(PushToken $token, PushMessage $message): bool
    {
        Log::info('push (not sent: Firebase is not set up)', [
            'type' => $message->type,
            'title' => $message->title,
            'token' => substr($token->token, 0, 8).'…',
        ]);

        return true;
    }
}
