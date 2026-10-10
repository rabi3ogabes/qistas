<?php

namespace App\Notifications\Alerts;

use App\Models\AppNotification;
use App\Models\NotificationLogEntry;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use Illuminate\Support\Facades\DB;

/**
 * Tells one person one thing (Win Plan PP9), in the workspace in context: an entry in their inbox, a line in the log for
 * each instalment it covers (whose unique dedupe key makes the same alert impossible to send twice), then a push to each
 * of their phones.
 */
final class Notifier
{
    public function __construct(private readonly PushSender $sender) {}

    /**
     * The keys among [$keys] already decided (sent or skipped).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function decided(array $keys): array
    {
        return $keys === [] ? [] : NotificationLogEntry::query()->whereIn('dedupe_key', $keys)->pluck('dedupe_key')->all();
    }

    /**
     * @param  list<array{type: string, subject_type: ?string, subject_id: ?string, dedupe_key: string}>  $covers
     */
    public function notify(User $user, PushMessage $message, array $covers): void
    {
        DB::transaction(function () use ($user, $message, $covers): void {
            AppNotification::create([
                'user_id' => $user->id,
                'type' => $message->type,
                'title' => $message->title,
                'body' => $message->body,
                'data' => $message->data,
            ]);
            foreach ($covers as $cover) {
                NotificationLogEntry::create($cover + ['user_id' => $user->id, 'channel' => 'push', 'status' => 'sent', 'sent_at' => now()]);
            }
        });

        foreach (PushToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->get() as $token) {
            $this->sender->send($token, $message);
        }
    }

    /** Records that there was nothing to say, so the same question is not asked again until the next one is due. */
    public function skip(User $user, string $type, string $key): void
    {
        NotificationLogEntry::create([
            'user_id' => $user->id, 'type' => $type, 'channel' => 'push', 'status' => 'skipped', 'dedupe_key' => $key, 'sent_at' => now(),
        ]);
    }
}
