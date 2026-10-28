<?php

namespace App\Notifications\Alerts;

use App\Mail\NoticeMail;
use App\Models\AppNotification;
use App\Models\NotificationLogEntry;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\Channels;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use App\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Tells one person one thing (Win Plan PP9, Sprint 3 Task 1b), in the workspace in context, on the channels Channels
 * decides: in the app (an entry in their inbox, then a push to each of their phones), by e-mail, by text message. A line
 * in the log for each thing it covers has a unique dedupe key, so the same notice can never go twice, even when it goes
 * nowhere because the person switched it off.
 */
final class Notifier
{
    public function __construct(
        private readonly PushSender $sender,
        private readonly Channels $channels,
        private readonly SmsSender $sms,
    ) {}

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
     * @param  bool  $interrupt  false keeps the notice without disturbing anyone (for a person in their quiet hours): the
     *                           inbox entry is made and the log line is written, but nothing is pushed to a phone and no
     *                           e-mail or text message is sent. A person who takes the kind outside the app only has no
     *                           inbox to keep it in, so for them it goes nowhere.
     */
    public function notify(User $user, PushMessage $message, array $covers, bool $interrupt = true): void
    {
        $channels = $this->channels->for($user, $message->type);
        if (! $interrupt) {
            $channels = array_values(array_intersect($channels, ['push']));
        }
        $inApp = in_array('push', $channels, true);

        DB::transaction(function () use ($user, $message, $covers, $channels, $inApp): void {
            if ($inApp) {
                AppNotification::create([
                    'user_id' => $user->id,
                    'type' => $message->type,
                    'title' => $message->title,
                    'body' => $message->body,
                    'data' => $message->data,
                ]);
            }
            foreach ($covers as $cover) {
                NotificationLogEntry::create($cover + [
                    'user_id' => $user->id, 'channel' => $channels === [] ? 'none' : implode(',', $channels),
                    'status' => $channels === [] ? 'skipped' : 'sent', 'sent_at' => now(),
                ]);
            }
        });

        if ($inApp && $interrupt) {
            foreach (PushToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->get() as $token) {
                $this->sender->send($token, $message);
            }
        }
        if (in_array('email', $channels, true)) {
            Mail::to($user)->locale($user->locale ?: config('app.locale'))->queue(new NoticeMail($message));
        }
        if (in_array('sms', $channels, true)) {
            $this->sms->send((string) $user->getAttribute('phone'), $message->title.': '.$message->body);
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
