<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

/**
 * Each person's alert choices in a workspace (Win Plan PP9), with the defaults for anything they never changed: the
 * summary at nine, late alerts weekly, due-date alerts off (the summary already says who pays today), no quiet hours.
 *
 * Reads and writes happen in the workspace in context (CurrentTenant), like every tenant-owned record.
 */
final class Preferences
{
    public const DEFAULTS = [
        'daily_digest' => ['enabled' => true, 'time' => '09:00'],
        'instalment_due' => ['enabled' => false],
        'instalment_late' => ['enabled' => true, 'repeat' => 'weekly'],
        'quiet_hours' => ['enabled' => false, 'from' => '22:00', 'to' => '08:00'],
    ];

    /** How many days apart a late alert repeats. */
    public const REPEAT_DAYS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30];

    private const TIME = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'daily_digest' => ['sometimes', 'array:enabled,time'],
            'daily_digest.enabled' => ['sometimes', 'boolean'],
            'daily_digest.time' => ['sometimes', 'string', self::TIME],
            'instalment_due' => ['sometimes', 'array:enabled'],
            'instalment_due.enabled' => ['sometimes', 'boolean'],
            'instalment_late' => ['sometimes', 'array:enabled,repeat'],
            'instalment_late.enabled' => ['sometimes', 'boolean'],
            'instalment_late.repeat' => ['sometimes', Rule::in(array_keys(self::REPEAT_DAYS))],
            'quiet_hours' => ['sometimes', 'array:enabled,from,to'],
            'quiet_hours.enabled' => ['sometimes', 'boolean'],
            'quiet_hours.from' => ['sometimes', 'string', self::TIME],
            'quiet_hours.to' => ['sometimes', 'string', self::TIME],
        ];
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
                $all[$row->user_id][$row->type] = array_merge(self::DEFAULTS[$row->type], $row->settings ?? [], ['enabled' => $row->enabled]);
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
            $merged = array_merge($current[$type], $values);
            $settings = array_diff_key($merged, ['enabled' => true]);

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'type' => $type, 'channel' => 'push'],
                ['enabled' => (bool) $merged['enabled'], 'settings' => $settings === [] ? null : $settings],
            );
        }

        return self::for($userId);
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
