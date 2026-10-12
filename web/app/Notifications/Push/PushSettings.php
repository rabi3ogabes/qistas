<?php

namespace App\Notifications\Push;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;

/**
 * The Firebase service account that sends pushes to Android phones and iPhones (Sprint 3 Task 1b), saved by a super
 * admin in /admin/notifications, encrypted and never shown back. The QISTAS_FCM_CREDENTIALS environment variable, when
 * set, still wins.
 */
final class PushSettings
{
    public const KEY = 'push.fcm_account';

    /** @return array<string, mixed>|null the service account, when one is saved or set in the environment */
    public function account(): ?array
    {
        $raw = trim((string) config('qistas.push.fcm_credentials'));
        if ($raw === '') {
            $stored = PlatformSetting::query()->whereKey(self::KEY)->value('value');
            $raw = is_string($stored) && $stored !== '' ? Crypt::decryptString($stored) : '';
        }
        $account = $raw === '' ? null : (json_decode($raw, true) ?? json_decode((string) base64_decode($raw, true), true));

        return self::valid($account) ? $account : null;
    }

    /** The Firebase project the pushes go through, to show the admin which one is in use. */
    public function project(): ?string
    {
        $project = $this->account()['project_id'] ?? null;

        return is_string($project) ? $project : null;
    }

    /** @param  mixed  $account */
    public static function valid($account): bool
    {
        return is_array($account) && is_string($account['project_id'] ?? null) && is_string($account['client_email'] ?? null)
            && is_string($account['private_key'] ?? null) && str_contains($account['private_key'], 'PRIVATE KEY');
    }

    /** Keeps a new service account (its JSON as Google gives it), or removes it (null). Audited, never with the key. */
    public function save(?string $json, ?User $by): void
    {
        $setting = PlatformSetting::query()->find(self::KEY) ?? (new PlatformSetting)->forceFill(['key' => self::KEY]);
        $setting->forceFill(['value' => $json === null ? null : Crypt::encryptString(trim($json)), 'updated_by_user_id' => $by?->id])->save();

        Audit::record($json === null ? 'push.key_removed' : 'push.key_saved', null, $json === null ? [] : ['project' => json_decode($json, true)['project_id'] ?? null], null, $by?->id);
    }
}
