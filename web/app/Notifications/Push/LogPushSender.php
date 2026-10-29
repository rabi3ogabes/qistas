<?php

namespace App\Notifications\Push;

use App\Models\PushToken;
use Illuminate\Support\Facades\Log;

/**
 * Until the owner gives the Firebase key, a push is only written to the log (its kind and title, never a token in full;
 * a silent push has no title, so its kind alone). The inbox still has every alert.
 */
final class LogPushSender implements PushSender
{
    public function send(PushToken $token, PushMessage $message): bool
    {
        // A silent push has no title; its type says all there is to say.
        Log::info('push (not sent: Firebase is not set up)', [
            'type' => $message->type,
            'token' => substr($token->token, 0, 8).'…',
        ] + ($message->silent ? ['silent' => true] : ['title' => $message->title]));

        return true;
    }
}
