<?php

namespace App\Auth;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Whether the admin team must use two-factor (owner's decision, 2026-10-28). Off at first: each person turns their own
 * on or off on the security page, and the console opens either way. A super admin can require it for everyone again in
 * Admin → Team security; the console then sends anyone without it to set it up (RequireTwoFactorForAdmins).
 */
final class TeamTwoFactor
{
    public const SETTING = 'security.require_team_two_factor';

    /** Read once per request: the admin middleware and the pages ask more than once. */
    private ?bool $required = null;

    public function required(): bool
    {
        return $this->required ??= PlatformSetting::query()->whereKey(self::SETTING)->value('value') === '1';
    }

    /**
     * @throws ValidationException (required) when $by would require it while their own two-factor is off: the rule
     *                                        would shut them out of the console they are changing it from
     */
    public function set(bool $required, User $by): void
    {
        if ($required && ! $by->hasConfirmedTwoFactor()) {
            throw ValidationException::withMessages(['required' => __('Turn on your own two-factor first, so the rule does not lock you out.')]);
        }
        if ($required === $this->required()) {
            return;
        }

        $setting = PlatformSetting::query()->find(self::SETTING) ?? (new PlatformSetting)->forceFill(['key' => self::SETTING]);
        $setting->forceFill(['value' => $required ? '1' : '0', 'updated_by_user_id' => $by->id])->save();
        $this->required = $required;
        Audit::record('admin.team_two_factor_changed', changes: ['required' => $required], userId: $by->id);
    }
}
