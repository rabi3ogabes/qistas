<?php

namespace App\Notifications;

use App\Mail\MailSettings;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Sms\SmsSender;
use App\Support\Audit;

/**
 * Where a notice may go (Sprint 3 Task 1b). Three things must agree: the channel is set up (in the app always; e-mail
 * once an SMTP server is saved; text messages once a provider exists), the admin allows that kind on that channel for
 * everyone, and the person chose it. E-mail also needs a verified address, and a text message a phone number.
 */
final class Channels
{
    public const KEY = 'notifications.allowed';

    public function __construct(private readonly MailSettings $mail, private readonly SmsSender $sms) {}

    /** @return array{push: bool, email: bool, sms: bool} */
    public function available(): array
    {
        return ['push' => true, 'email' => $this->mail->configured() || $this->mail->serverDecides(), 'sms' => $this->sms->available()];
    }

    /**
     * What the admin allows, kind by kind: everything, until they say otherwise. A grid the admin saved holds every kind
     * there was at the time, so a kind it does not name at all is newer than the grid, and nothing has been said about it.
     *
     * @return array<string, array{push: bool, email: bool, sms: bool}>
     */
    public function allowed(): array
    {
        $stored = json_decode((string) PlatformSetting::query()->whereKey(self::KEY)->value('value'), true);
        $allowed = [];
        foreach (array_keys(Catalog::KINDS) as $kind) {
            $said = is_array($stored) && is_array($stored[$kind] ?? null);
            foreach (Catalog::CHANNELS as $channel) {
                $allowed[$kind][$channel] = $said ? (bool) ($stored[$kind][$channel] ?? false) : true;
            }
        }

        /** @var array<string, array{push: bool, email: bool, sms: bool}> $allowed */
        return $allowed;
    }

    /**
     * Keeps the admin's grid; a kind or channel left out is not allowed. Audited with the whole grid.
     *
     * @param  array<string, array<string, mixed>>  $allowed
     */
    public function setAllowed(array $allowed, ?User $by): void
    {
        $grid = [];
        foreach (array_keys(Catalog::KINDS) as $kind) {
            foreach (Catalog::CHANNELS as $channel) {
                $grid[$kind][$channel] = (bool) ($allowed[$kind][$channel] ?? false);
            }
        }

        $setting = PlatformSetting::query()->find(self::KEY) ?? (new PlatformSetting)->forceFill(['key' => self::KEY]);
        $setting->forceFill(['value' => json_encode($grid), 'updated_by_user_id' => $by?->id])->save();
        Audit::record('notifications.allowed_changed', null, ['allowed' => $grid], null, $by?->id);
    }

    /**
     * The channels a notice of [$type] goes out on for [$user], in the workspace in context: none when they switched the
     * kind off. A type no kind names goes to the app, as it always has.
     *
     * @return list<string>
     */
    public function for(User $user, string $type): array
    {
        $kind = Catalog::kindOf($type);
        if ($kind === null) {
            return ['push'];
        }

        $choice = Preferences::for($user->id)[$kind];
        if (! $choice['enabled']) {
            return [];
        }

        $available = $this->available();
        $allowed = $this->allowed()[$kind];
        $phone = $user->getAttribute('phone');

        return array_values(array_filter(Catalog::CHANNELS, fn (string $channel): bool => ($choice['channels'][$channel] ?? false)
            && $allowed[$channel] && $available[$channel]
            && ($channel !== 'email' || $user->hasVerifiedEmail())
            && ($channel !== 'sms' || (is_string($phone) && $phone !== ''))));
    }
}
