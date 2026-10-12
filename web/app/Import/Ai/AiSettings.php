<?php

namespace App\Import\Ai;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;

/**
 * The owner's Anthropic key and the model that reads photos and PDFs (Win Plan K9, decision D8), in platform_settings.
 * The key is encrypted and never shown back; without one, reading photos and PDFs is not offered at all.
 */
final class AiSettings
{
    public const KEY = 'ai.anthropic_key';

    public const MODEL = 'ai.model';

    /** Fast and careful with handwriting and Arabic; the decision's choice. */
    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    public function key(): ?string
    {
        $value = PlatformSetting::query()->whereKey(self::KEY)->value('value');

        return is_string($value) && $value !== '' ? Crypt::decryptString($value) : null;
    }

    public function configured(): bool
    {
        return $this->key() !== null;
    }

    public function model(): string
    {
        $value = PlatformSetting::query()->whereKey(self::MODEL)->value('value');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_MODEL;
    }

    /** Keeps a new key in place of the old one, or removes it (null). Audited, never with the key. */
    public function setKey(?string $key, ?User $by): void
    {
        $key = $key === null ? null : trim($key);
        $setting = PlatformSetting::query()->find(self::KEY) ?? (new PlatformSetting)->forceFill(['key' => self::KEY]);
        $setting->forceFill(['value' => $key === null || $key === '' ? null : Crypt::encryptString($key), 'updated_by_user_id' => $by?->id])->save();

        Audit::record($key === null || $key === '' ? 'ai.key_removed' : 'ai.key_saved', null, [], null, $by?->id);
    }

    public function setModel(?string $model, ?User $by): void
    {
        $setting = PlatformSetting::query()->find(self::MODEL) ?? (new PlatformSetting)->forceFill(['key' => self::MODEL]);
        $setting->forceFill(['value' => $model === null || trim($model) === '' ? null : trim($model), 'updated_by_user_id' => $by?->id])->save();
    }
}
