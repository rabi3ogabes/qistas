<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

/**
 * Each person's notice choices in a workspace (Win Plan PP9, Sprint 3 Task 1b), with the defaults for anything they never
 * changed: the summary at nine, late alerts weekly, due-date alerts off (the summary already says who pays today), no
 * quiet hours, and every billing, support and end-of-day notice on. Each kind also says where it goes (`channels`: in the app, by
 * e-mail, by text message; see Catalog and Channels).
 *
 * Reads and writes happen in the workspace in context (CurrentTenant), like every tenant-owned record.
 */
final class Preferences
{
    public const DEFAULTS = [
        'daily_digest' => ['enabled' => true, 'time' => '09:00', 'channels' => Catalog::KINDS['daily_digest']['channels']],
        'instalment_due' => ['enabled' => false, 'channels' => Catalog::KINDS['instalment_due']['channels']],
        'instalment_late' => ['enabled' => true, 'repeat' => 'weekly', 'channels' => Catalog::KINDS['instalment_late']['channels']],
        'day_close_reminder' => ['enabled' => true, 'channels' => Catalog::KINDS['day_close_reminder']['channels']],
        'day_close_difference' => ['enabled' => true, 'channels' => Catalog::KINDS['day_close_difference']['channels']],
        'billing_renewal_due' => ['enabled' => true, 'channels' => Catalog::KINDS['billing_renewal_due']['channels']],
        'billing_renewed' => ['enabled' => true, 'channels' => Catalog::KINDS['billing_renewed']['channels']],
        'billing_payment_failed' => ['enabled' => true, 'channels' => Catalog::KINDS['billing_payment_failed']['channels']],
        'billing_expired' => ['enabled' => true, 'channels' => Catalog::KINDS['billing_expired']['channels']],
        'support_reply' => ['enabled' => true, 'channels' => Catalog::KINDS['support_reply']['channels']],
        'quiet_hours' => ['enabled' => false, 'from' => '22:00', 'to' => '08:00'],
    ];

    /** How many days apart a late alert repeats. */
    public const REPEAT_DAYS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $rules = [
            'daily_digest' => ['sometimes', 'array:enabled,time,channels'],
            'daily_digest.time' => ['sometimes', 'string', self::TIME],
            'instalment_due' => ['sometimes', 'array:enabled,channels'],
            'instalment_late' => ['sometimes', 'array:enabled,repeat,channels'],
            'instalment_late.repeat' => ['sometimes', Rule::in(array_keys(self::REPEAT_DAYS))],
            'quiet_hours' => ['sometimes', 'array:enabled,from,to'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.from' => ['sometimes', 'string', self::TIME],
            'quiet_hours.to' => ['sometimes', 'string', self::TIME],
        ];
        foreach (array_keys(Catalog::KINDS) as $kind) {
            $rules[$kind] ??= ['sometimes', 'array:enabled,channels'];
            $rules[$kind.'.enabled'] = ['sometimes', 'boolean'];
            $rules[$kind.'.channels'] = ['sometimes', 'array:'.implode(',', Catalog::CHANNELS)];
            foreach (Catalog::CHANNELS as $channel) {
                $rules[$kind.'.channels.'.$channel] = ['sometimes', 'boolean'];
            }
        }

        return $rules;
    }

    /**
     * The choices of several people in the workspace in context, read in one query.
     *
     * @param  list<string>  $userIds
     * @return array<string, array<string, array<string, mixed>>> by user id, then by kind
     */
    public static function forUsers(array $userIds): array
    {
        $rows = NotificationPreference::query()->whereIn('user_id', $userIds)->where('channel', 'push')->get();

        $all = [];
        foreach ($userIds as $userId) {
            $all[$userId] = self::DEFAULTS;
        }
        foreach ($rows as $row) {
            if (isset(self::DEFAULTS[$row->type])) {
                $all[$row->user_id][$row->type] = self::merge(self::DEFAULTS[$row->type], ($row->settings ?? []) + ['enabled' => $row->enabled]);
            }
        }

        return $all;
    }

    /** @return array<string, array<string, mixed>> */
    public static function for(string $userId): array
    {
        return self::forUsers([$userId])[$userId];
    }

    /**
     * Saves what was sent (already validated), kind by kind, and gives back the whole set.
     *
     * @param  array<string, array<string, mixed>>  $changes
     * @return array<string, array<string, mixed>>
     */
    public static function update(string $userId, array $changes): array
    {
        $current = self::for($userId);

        foreach ($changes as $type => $values) {
            if (! isset(self::DEFAULTS[$type])) {
                continue;
            }
            $merged = self::merge($current[$type], $values);
            $settings = array_diff_key($merged, ['enabled' => true]);

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'type' => $type, 'channel' => 'push'],
                ['enabled' => (bool) $merged['enabled'], 'settings' => $settings === [] ? null : $settings],
            );
        }

        return self::for($userId);
    }

    /**
     * [$changes] over [$choice], with the channels merged one by one: changing one channel never forgets the others.
     *
     * @param  array<string, mixed>  $choice
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function merge(array $choice, array $changes): array
    {
        $merged = array_merge($choice, $changes);
        if (isset($choice['channels'])) {
            $merged['channels'] = array_map('boolval', array_intersect_key(
                array_merge($choice['channels'], is_array($changes['channels'] ?? null) ? $changes['channels'] : []),
                array_flip(Catalog::CHANNELS),
            ));
        }

        return $merged;
    }

    /** Is [$local] (on the workspace's clock) inside this person's quiet hours? A span may cross midnight. */
    /** @param  array<string, array<string, mixed>>  $preferences */
    public static function isQuiet(array $preferences, CarbonInterface $local): bool
    {
        $quiet = $preferences['quiet_hours'];
        if (! $quiet['enabled'] || $quiet['from'] === $quiet['to']) {
            return false;
        }

        $now = $local->format('H:i');

        return $quiet['from'] < $quiet['to']
            ? $now >= $quiet['from'] && $now < $quiet['to']
            : $now >= $quiet['from'] || $now < $quiet['to'];
    }
}
