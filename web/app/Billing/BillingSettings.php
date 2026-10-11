<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The platform's billing settings (Win Plan PP13), written by a super admin in /admin/billing: whether a bank transfer
 * turns a plan on by itself, the bank details owners pay to, and the Google Play keys. The service account is stored
 * encrypted and never shown again once saved.
 */
final class BillingSettings
{
    public const AUTO_ACTIVATE = 'billing.auto_activate';

    public const BANK_DETAILS = 'billing.bank_details';

    public const PLAY_PACKAGE = 'billing.play.package';

    public const PLAY_ACCOUNT = 'billing.play.service_account';

    public const PLAY_PRODUCTS = 'billing.play.products';

    public const PUSH_AUDIENCE = 'billing.play.push_audience';

    public const PUSH_EMAIL = 'billing.play.push_email';

    /** Stored encrypted; read back only by the code that uses them. */
    private const SECRET = [self::PLAY_ACCOUNT];

    /** A bank transfer request turns the plan on at once instead of waiting for the team. */
    public function autoActivate(): bool
    {
        return $this->get(self::AUTO_ACTIVATE) === '1';
    }

    /** What owners transfer to: bank, account name, IBAN, as the team writes it. */
    public function bankDetails(): ?string
    {
        return $this->get(self::BANK_DETAILS);
    }

    /** The app's package name on Google Play. */
    public function playPackage(): ?string
    {
        return $this->get(self::PLAY_PACKAGE);
    }

    /**
     * The Google Cloud service account allowed to read the app's purchases, as the JSON file Google gives.
     *
     * @return array<string, mixed>|null
     */
    public function playAccount(): ?array
    {
        $raw = $this->get(self::PLAY_ACCOUNT);
        $account = $raw === null ? null : json_decode($raw, true);

        return is_array($account) && isset($account['client_email'], $account['private_key']) ? $account : null;
    }

    /** @return array<string, string> the Google Play subscription id of each paid plan, by plan key */
    public function playProducts(): array
    {
        $products = json_decode((string) $this->get(self::PLAY_PRODUCTS), true);

        return is_array($products) ? array_filter($products, fn ($id, $key) => is_string($key) && is_string($id) && $id !== '', ARRAY_FILTER_USE_BOTH) : [];
    }

    /** The paid plan a Google Play subscription buys, or null for a product this platform does not sell. */
    public function planForProduct(string $productId): ?Plan
    {
        $key = array_search($productId, $this->playProducts(), true);

        return is_string($key) ? Plan::query()->where('key', $key)->first() : null;
    }

    /** Everything Google Play needs is in place: the package, the service account and at least one product. */
    public function playReady(): bool
    {
        return $this->playPackage() !== null && $this->playAccount() !== null && $this->playProducts() !== [];
    }

    /** The audience Google writes into the token it signs for each notification (the push endpoint's address). */
    public function pushAudience(): ?string
    {
        return $this->get(self::PUSH_AUDIENCE);
    }

    /** The service account the Pub/Sub push subscription signs its notifications as. */
    public function pushEmail(): ?string
    {
        return $this->get(self::PUSH_EMAIL);
    }

    public function set(string $key, ?string $value, ?User $by = null): void
    {
        $value = $value === null || trim($value) === '' ? null : trim($value);

        $setting = PlatformSetting::query()->find($key) ?? (new PlatformSetting)->forceFill(['key' => $key]);
        $setting->forceFill([
            'value' => $value !== null && in_array($key, self::SECRET, true) ? Crypt::encryptString($value) : $value,
            'updated_by_user_id' => $by?->id,
        ])->save();
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    private function get(string $key): ?string
    {
        $value = PlatformSetting::query()->whereKey($key)->value('value');
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (! in_array($key, self::SECRET, true)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null; // written under another APP_KEY: as good as not set
        }
    }
}
