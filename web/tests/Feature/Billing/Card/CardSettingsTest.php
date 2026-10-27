<?php

use App\Billing\Card\CardSettings;
use App\Billing\Card\StripeGateway;
use App\Billing\Card\UnconfiguredStripeGateway;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/*
| The Stripe keys and card options the super admin saves in /admin/billing (spec 2026-10-27 §8, D10): secrets stored
| encrypted and never shown back whole, the test or live mode read from the keys, a mixed pair refused, and an
| environment value winning over the saved one.
*/

function cardSettingsAdmin(): User
{
    return User::factory()->create(['platform_role' => 'super_admin']);
}

it('saves the keys encrypted and reads them back', function () {
    $settings = app(CardSettings::class);

    $settings->saveKeys('sk_test_51AbcSecretKey0001', 'pk_test_51AbcPublicKey0001', cardSettingsAdmin());

    expect($settings->secretKey())->toBe('sk_test_51AbcSecretKey0001')
        ->and($settings->publishableKey())->toBe('pk_test_51AbcPublicKey0001')
        ->and(PlatformSetting::query()->whereKey('billing.card.secret')->value('value'))->not->toContain('sk_test_51AbcSecretKey0001')
        ->and(PlatformSetting::query()->whereKey('billing.card.publishable')->value('value'))->not->toContain('pk_test_51AbcPublicKey0001');
});

it('reads the mode from the keys', function (string $secret, string $publishable, string $mode) {
    $settings = app(CardSettings::class);
    $settings->saveKeys($secret, $publishable, cardSettingsAdmin());

    expect($settings->mode())->toBe($mode);
})->with([
    ['sk_test_51Abc0001', 'pk_test_51Abc0001', 'test'],
    ['sk_live_51Abc0001', 'pk_live_51Abc0001', 'live'],
    ['rk_live_51Abc0001', 'pk_live_51Abc0001', 'live'],
]);

it('refuses keys that are not Stripe keys or mix test and live', function (string $secret, string $publishable, string $field) {
    try {
        app(CardSettings::class)->saveKeys($secret, $publishable, cardSettingsAdmin());
        $this->fail('The keys were accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(app(CardSettings::class)->secretKey())->toBeNull();
})->with([
    'test secret with a live publishable key' => ['sk_test_51Abc0001', 'pk_live_51Abc0001', 'publishable_key'],
    'a publishable key in the secret field' => ['pk_test_51Abc0001', 'pk_test_51Abc0001', 'secret_key'],
    'a secret key in the publishable field' => ['sk_test_51Abc0001', 'sk_test_51Abc0002', 'publishable_key'],
    'nonsense' => ['hello', 'world', 'secret_key'],
]);

it('is ready only with both keys and the webhook secret, in one mode', function () {
    $settings = app(CardSettings::class);
    expect($settings->ready())->toBeFalse();

    $settings->saveKeys('sk_test_51Abc0001', 'pk_test_51Abc0001', cardSettingsAdmin());
    expect($settings->ready())->toBeFalse();

    $settings->saveWebhook('we_123', 'whsec_abc123');
    expect($settings->ready())->toBeTrue()
        ->and($settings->webhookEndpointId())->toBe('we_123')
        ->and($settings->webhookSecret())->toBe('whsec_abc123')
        ->and(PlatformSetting::query()->whereKey('billing.card.webhook_secret')->value('value'))->not->toContain('whsec_abc123');
});

it('lets the environment win over the saved keys', function () {
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_test_51Saved0001', 'pk_test_51Saved0001', cardSettingsAdmin());
    config([
        'qistas.billing.stripe.secret' => 'sk_live_51Env0001',
        'qistas.billing.stripe.publishable' => 'pk_live_51Env0001',
        'qistas.billing.stripe.webhook_secret' => 'whsec_env',
    ]);

    expect($settings->secretKey())->toBe('sk_live_51Env0001')
        ->and($settings->publishableKey())->toBe('pk_live_51Env0001')
        ->and($settings->webhookSecret())->toBe('whsec_env')
        ->and($settings->mode())->toBe('live');
});

it('shows only the start and the last four of the secret key', function () {
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_live_51AbcDefGhiJklA1B2', 'pk_live_51AbcDefGhiJkl', cardSettingsAdmin());

    expect($settings->maskedSecret())->toBe('sk_live_…A1B2');
});

it('forgets the keys and the webhook on clear', function () {
    $settings = app(CardSettings::class);
    $admin = cardSettingsAdmin();
    $settings->saveKeys('sk_test_51Abc0001', 'pk_test_51Abc0001', $admin);
    $settings->saveWebhook('we_123', 'whsec_abc123');

    $settings->clear($admin);

    expect($settings->secretKey())->toBeNull()->and($settings->publishableKey())->toBeNull()
        ->and($settings->webhookSecret())->toBeNull()->and($settings->webhookEndpointId())->toBeNull()
        ->and($settings->ready())->toBeFalse();
});

it('has sensible options until the admin changes them', function () {
    $settings = app(CardSettings::class);

    expect($settings->trialDays())->toBe(14)
        ->and($settings->promotionCodes())->toBeFalse()
        ->and($settings->collectTaxId())->toBeFalse()
        ->and($settings->automaticTax())->toBeFalse();
});

it('saves the options, keeping the trial between 0 and 90 days', function () {
    $settings = app(CardSettings::class);
    $admin = cardSettingsAdmin();

    $settings->saveOptions(['trial_days' => 0, 'promotion_codes' => true, 'collect_tax_id' => true, 'automatic_tax' => false], $admin);
    expect($settings->trialDays())->toBe(0)->and($settings->promotionCodes())->toBeTrue()->and($settings->collectTaxId())->toBeTrue();

    expect(fn () => $settings->saveOptions(['trial_days' => 91, 'promotion_codes' => false, 'collect_tax_id' => false, 'automatic_tax' => false], $admin))
        ->toThrow(ValidationException::class);
    expect($settings->trialDays())->toBe(0);
});

it('records saving the settings with key names only', function () {
    app(CardSettings::class)->saveKeys('sk_test_51Abc0001', 'pk_test_51Abc0001', $admin = cardSettingsAdmin());

    $entry = AuditLog::query()->where('action', 'admin.card_settings_saved')->sole();
    expect($entry->user_id)->toBe($admin->id)
        ->and(json_encode($entry->changes))->not->toContain('sk_test_51Abc0001')
        ->and(json_encode($entry->changes))->not->toContain('pk_test_51Abc0001');
});

it('talks to no Stripe while there are no keys', function () {
    expect(app(StripeGateway::class))->toBeInstanceOf(UnconfiguredStripeGateway::class);
});
