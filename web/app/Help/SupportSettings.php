<?php

namespace App\Help;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;

/**
 * How people reach the Qistas team (Win Plan PP17): the support WhatsApp number a super admin sets in /admin/support, and
 * the support code that names a workspace in a message without revealing anything private.
 */
final class SupportSettings
{
    public const WHATSAPP = 'support.whatsapp';

    /** The number in international form, digits only (as wa.me wants it), or null until it is set. */
    public function whatsapp(): ?string
    {
        $value = PlatformSetting::query()->whereKey(self::WHATSAPP)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setWhatsapp(?string $number, ?User $by): void
    {
        $digits = $number === null ? '' : (string) preg_replace('/\D+/', '', $number);
        $setting = PlatformSetting::query()->find(self::WHATSAPP) ?? (new PlatformSetting)->forceFill(['key' => self::WHATSAPP]);
        $setting->forceFill(['value' => $digits === '' ? null : $digits, 'updated_by_user_id' => $by?->id])->save();
    }

    /**
     * A short code for the workspace that staff can look up: the random end of its id. It names nobody and opens
     * nothing on its own.
     */
    public static function code(Tenant $tenant): string
    {
        return 'Q-'.strtoupper(substr(str_replace('-', '', $tenant->id), -6));
    }
}
