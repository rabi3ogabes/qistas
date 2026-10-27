<?php

namespace App\Billing\Card;

use App\Billing\Card\Data\CardAccount;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pro by card's settings (spec 2026-10-27 §8, D10), written by a super admin in /admin/billing: the Stripe keys, the
 * webhook endpoint Connect made and its signing secret, and the card options. Keys and the signing secret are stored
 * encrypted and never shown back whole. A key in the environment (STRIPE_SECRET, STRIPE_PUBLISHABLE_KEY,
 * STRIPE_WEBHOOK_SECRET) wins over the saved one, which is how a developer tests locally with their own test keys.
 */
final class CardSettings
{
    public const SECRET = 'billing.card.secret';

    public const PUBLISHABLE = 'billing.card.publishable';

    public const WEBHOOK_SECRET = 'billing.card.webhook_secret';

    public const WEBHOOK_ID = 'billing.card.webhook_id';

    public const TRIAL_DAYS = 'billing.card.trial_days';

    public const PROMOTION_CODES = 'billing.card.promotion_codes';

    public const COLLECT_TAX_ID = 'billing.card.collect_tax_id';

    public const AUTOMATIC_TAX = 'billing.card.automatic_tax';

    public const ACCOUNT = 'billing.card.account';

    public const DEFAULT_TRIAL_DAYS = 14;

    public const MAX_TRIAL_DAYS = 90;

    private const ENCRYPTED = [self::SECRET, self::PUBLISHABLE, self::WEBHOOK_SECRET];

    public function secretKey(): ?string
    {
        return $this->fromEnv('secret') ?? $this->get(self::SECRET);
    }

    public function publishableKey(): ?string
    {
        return $this->fromEnv('publishable') ?? $this->get(self::PUBLISHABLE);
    }

    public function webhookSecret(): ?string
    {
        return $this->fromEnv('webhook_secret') ?? $this->get(self::WEBHOOK_SECRET);
    }

    public function webhookEndpointId(): ?string
    {
        return $this->get(self::WEBHOOK_ID);
    }

    /** The server's environment sets the secret key, so the keys saved here are not the ones in use. */
    public function keysFromEnvironment(): bool
    {
        return $this->fromEnv('secret') !== null;
    }

    /**
     * The Stripe account as Connect last found it, for the admin panel; null before the first Connect.
     *
     * @return array{id: string, name: ?string, country: string, currency: string, charges_enabled: bool, livemode: bool, wallets: bool}|null
     */
    public function account(): ?array
    {
        $account = json_decode((string) $this->get(self::ACCOUNT), true);

        return is_array($account) && is_string($account['id'] ?? null) ? $account + [
            'name' => null, 'country' => '', 'currency' => '', 'charges_enabled' => false, 'livemode' => false, 'wallets' => false,
        ] : null;
    }

    /** @param  bool  $wallets  whether Stripe registered this site's domain for Apple Pay and Google Pay */
    public function saveAccount(CardAccount $account, bool $wallets): void
    {
        $this->set(self::ACCOUNT, (string) json_encode([
            'id' => $account->id, 'name' => $account->name, 'country' => $account->country, 'currency' => $account->currency,
            'charges_enabled' => $account->chargesEnabled, 'livemode' => $account->livemode, 'wallets' => $wallets,
        ]));
    }

    /** test or live, read from the secret key; null without one. */
    public function mode(): ?string
    {
        return self::modeOf($this->secretKey());
    }

    /** Both keys and the webhook's signing secret are there, and the keys belong to the same mode. */
    public function ready(): bool
    {
        $mode = $this->mode();

        return $mode !== null && self::modeOf($this->publishableKey(), 'pk') === $mode && $this->webhookSecret() !== null;
    }

    /** sk_live_…A1B2: enough to recognise the key, not to use it. */
    public function maskedSecret(): ?string
    {
        $key = $this->secretKey();
        if ($key === null) {
            return null;
        }

        return preg_replace('/^((?:sk|rk)_(?:test|live)_).*(.{4})$/', '$1…$2', $key) ?? '…';
    }

    /** @throws ValidationException (secret_key, publishable_key) when a key is not a Stripe key or the pair mixes modes */
    public function saveKeys(string $secret, string $publishable, User $by): void
    {
        $secret = trim($secret);
        $publishable = trim($publishable);
        $mode = self::modeOf($secret);
        if ($mode === null) {
            throw ValidationException::withMessages(['secret_key' => __('This is not a Stripe secret key. It starts with sk_test_ or sk_live_.')]);
        }
        $publishableMode = self::modeOf($publishable, 'pk');
        if ($publishableMode === null) {
            throw ValidationException::withMessages(['publishable_key' => __('This is not a Stripe publishable key. It starts with pk_test_ or pk_live_.')]);
        }
        if ($publishableMode !== $mode) {
            throw ValidationException::withMessages(['publishable_key' => __('Both keys must come from the same mode: two test keys, or two live keys.')]);
        }

        $this->set(self::SECRET, $secret, $by);
        $this->set(self::PUBLISHABLE, $publishable, $by);
        Audit::record('admin.card_settings_saved', changes: ['keys' => ['secret_key', 'publishable_key'], 'mode' => $mode], userId: $by->id);
    }

    /** The endpoint Connect created for this site and the signing secret Stripe gave for it. */
    public function saveWebhook(string $id, string $secret): void
    {
        $this->set(self::WEBHOOK_ID, $id);
        $this->set(self::WEBHOOK_SECRET, $secret);
    }

    /** Forgets the keys and the webhook (Disconnect). The options stay for the next connection. */
    public function clear(User $by): void
    {
        foreach ([self::SECRET, self::PUBLISHABLE, self::WEBHOOK_SECRET, self::WEBHOOK_ID, self::ACCOUNT] as $key) {
            $this->set($key, null, $by);
        }
        Audit::record('admin.card_settings_saved', changes: ['cleared' => ['secret_key', 'publishable_key', 'webhook']], userId: $by->id);
    }

    public function trialDays(): int
    {
        $days = $this->get(self::TRIAL_DAYS);

        return $days === null ? self::DEFAULT_TRIAL_DAYS : max(0, min(self::MAX_TRIAL_DAYS, (int) $days));
    }

    public function promotionCodes(): bool
    {
        return $this->get(self::PROMOTION_CODES) === '1';
    }

    public function collectTaxId(): bool
    {
        return $this->get(self::COLLECT_TAX_ID) === '1';
    }

    public function automaticTax(): bool
    {
        return $this->get(self::AUTOMATIC_TAX) === '1';
    }

    /**
     * @param  array{trial_days: int, promotion_codes: bool, collect_tax_id: bool, automatic_tax: bool}  $options
     *
     * @throws ValidationException (trial_days) outside 0 to MAX_TRIAL_DAYS
     */
    public function saveOptions(array $options, User $by): void
    {
        $days = $options['trial_days'];
        if ($days < 0 || $days > self::MAX_TRIAL_DAYS) {
            throw ValidationException::withMessages(['trial_days' => __('The free trial is between 0 and :max days.', ['max' => self::MAX_TRIAL_DAYS])]);
        }

        $this->set(self::TRIAL_DAYS, (string) $days, $by);
        $this->set(self::PROMOTION_CODES, $options['promotion_codes'] ? '1' : '0', $by);
        $this->set(self::COLLECT_TAX_ID, $options['collect_tax_id'] ? '1' : '0', $by);
        $this->set(self::AUTOMATIC_TAX, $options['automatic_tax'] ? '1' : '0', $by);
        Audit::record('admin.card_settings_saved', changes: [
            'trial_days' => $days, 'promotion_codes' => $options['promotion_codes'],
            'collect_tax_id' => $options['collect_tax_id'], 'automatic_tax' => $options['automatic_tax'],
        ], userId: $by->id);
    }

    /** test or live for a Stripe key with this prefix (sk and rk for secret keys, pk for publishable ones); null otherwise. */
    private static function modeOf(?string $key, string $kind = 'sk'): ?string
    {
        $prefixes = $kind === 'pk' ? 'pk' : '(?:sk|rk)';

        return $key !== null && preg_match('/^'.$prefixes.'_(test|live)_[A-Za-z0-9]+$/', $key, $match) === 1 ? $match[1] : null;
    }

    private function fromEnv(string $name): ?string
    {
        $value = config('qistas.billing.stripe.'.$name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function set(string $key, ?string $value, ?User $by = null): void
    {
        $setting = PlatformSetting::query()->find($key) ?? (new PlatformSetting)->forceFill(['key' => $key]);
        $setting->forceFill([
            'value' => $value !== null && in_array($key, self::ENCRYPTED, true) ? Crypt::encryptString($value) : $value,
            'updated_by_user_id' => $by?->id,
        ])->save();
    }

    private function get(string $key): ?string
    {
        $value = PlatformSetting::query()->whereKey($key)->value('value');
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (! in_array($key, self::ENCRYPTED, true)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null; // written under another APP_KEY: as good as not set
        }
    }
}
