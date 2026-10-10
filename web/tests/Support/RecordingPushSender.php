<?php

namespace Tests\Support;

use App\Models\PushToken;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;

/** Keeps every push instead of sending it, so a test can read what each phone would have shown. */
final class RecordingPushSender implements PushSender
{
    /** @var list<array{token: string, message: PushMessage}> */
    public array $sent = [];

    public function send(PushToken $token, PushMessage $message): bool
    {
        $this->sent[] = ['token' => $token->token, 'message' => $message];

        return true;
    }

    /** @return list<PushMessage> what reached this phone, oldest first */
    public function to(string $token): array
    {
        return array_values(array_map(fn (array $push) => $push['message'], array_filter($this->sent, fn (array $push) => $push['token'] === $token)));
    }
}
