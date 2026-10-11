<?php

use App\Billing\BillingSettings;
use App\Billing\Store\GooglePlayBilling;
use App\Billing\Store\PurchaseUnverifiable;
use App\Billing\Store\StoreBilling;
use App\Billing\Store\StoreUnavailable;
use App\Billing\Store\UnconfiguredStoreBilling;
use App\Models\PlatformSetting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Win Plan PP13: the Google Play Developer API as Qistas reads it. The service account gets an access token for the
| androidpublisher scope; a purchase is read from purchases.subscriptionsv2 and acknowledged once it is ours.
*/

function playAccount(): array
{
    // PHP on Windows finds no OpenSSL config of its own; it ships one beside the binary.
    $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($bundled) ? ['config' => $bundled] : []);
    openssl_pkey_export(openssl_pkey_new($options), $pem, null, $options);

    return ['type' => 'service_account', 'client_email' => 'play@qistas-test.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token'];
}

/** @param  array<string, mixed>  $overrides */
function googleSubscription(array $overrides = []): array
{
    return $overrides + [
        'kind' => 'androidpublisher#subscriptionPurchaseV2',
        'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
        'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
        'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'ref-123'],
        'lineItems' => [[
            'productId' => 'qistas_pro', 'expiryTime' => '2026-11-11T09:00:00.000Z',
            'autoRenewingPlan' => ['autoRenewEnabled' => true], 'offerDetails' => ['basePlanId' => 'monthly'],
        ]],
    ];
}

function fakeGoogle(int $status = 200, ?array $body = null): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600]),
        'androidpublisher.googleapis.com/*:acknowledge' => Http::response([], 200),
        'androidpublisher.googleapis.com/*' => Http::response($body ?? googleSubscription(), $status),
    ]);
}

it('reads a subscription from Google with the service account’s token', function () {
    fakeGoogle();

    $purchase = (new GooglePlayBilling('app.qistas', playAccount()))->verify('tok/1');

    expect($purchase->productId)->toBe('qistas_pro')->and($purchase->state)->toBe('active')
        ->and($purchase->expiresAt?->toIso8601String())->toBe('2026-11-11T09:00:00+00:00')
        ->and($purchase->autoRenew)->toBeTrue()->and($purchase->acknowledged)->toBeFalse()
        ->and($purchase->accountRef)->toBe('ref-123');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/app.qistas/purchases/subscriptionsv2/tokens/tok%2F1'
        && $r->hasHeader('Authorization', 'Bearer access-1'));
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://oauth2.googleapis.com/token') && str_contains((string) $r->body(), 'jwt-bearer'));
});

it('reads Google’s states in its own words', function (string $google, string $ours) {
    fakeGoogle(body: googleSubscription(['subscriptionState' => $google]));

    expect((new GooglePlayBilling('app.qistas', playAccount()))->verify('tok')->state)->toBe($ours);
})->with([
    ['SUBSCRIPTION_STATE_ACTIVE', 'active'],
    ['SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'grace'],
    ['SUBSCRIPTION_STATE_ON_HOLD', 'hold'],
    ['SUBSCRIPTION_STATE_PAUSED', 'paused'],
    ['SUBSCRIPTION_STATE_CANCELED', 'canceled'],
    ['SUBSCRIPTION_STATE_PENDING', 'pending'],
    ['SUBSCRIPTION_STATE_EXPIRED', 'expired'],
    ['SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED', 'expired'],
]);

it('takes the line that runs longest, and notices a free trial and a linked earlier purchase', function () {
    fakeGoogle(body: googleSubscription([
        'linkedPurchaseToken' => 'old-token',
        'lineItems' => [
            ['productId' => 'qistas_pro', 'expiryTime' => '2026-10-20T00:00:00Z'],
            ['productId' => 'qistas_pro', 'expiryTime' => '2026-10-25T00:00:00Z', 'offerPhase' => ['freeTrial' => (object) []]],
        ],
    ]));

    $purchase = (new GooglePlayBilling('app.qistas', playAccount()))->verify('tok');

    expect($purchase->expiresAt?->toDateString())->toBe('2026-10-25')->and($purchase->trial)->toBeTrue()->and($purchase->linkedToken)->toBe('old-token');
});

it('calls a token Google does not know unverifiable', function () {
    fakeGoogle(404, ['error' => ['code' => 404]]);

    expect(fn () => (new GooglePlayBilling('app.qistas', playAccount()))->verify('tok'))->toThrow(PurchaseUnverifiable::class);
});

it('calls Google failing unavailable, deciding nothing', function () {
    fakeGoogle(503, ['error' => ['code' => 503]]);

    expect(fn () => (new GooglePlayBilling('app.qistas', playAccount()))->verify('tok'))->toThrow(StoreUnavailable::class);
});

it('acknowledges a purchase where Google asks', function () {
    fakeGoogle();

    (new GooglePlayBilling('app.qistas', playAccount()))->acknowledge('qistas_pro', 'tok-1');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/app.qistas/purchases/subscriptions/qistas_pro/tokens/tok-1:acknowledge');
});

it('uses Google Play once the super admin has saved it, and decides nothing before', function () {
    expect(app(StoreBilling::class))->toBeInstanceOf(UnconfiguredStoreBilling::class)
        ->and(fn () => app(StoreBilling::class)->verify('tok'))->toThrow(StoreUnavailable::class);

    $settings = app(BillingSettings::class);
    $settings->set(BillingSettings::PLAY_PACKAGE, 'app.qistas');
    $settings->set(BillingSettings::PLAY_ACCOUNT, (string) json_encode(playAccount()));

    expect(app(StoreBilling::class))->toBeInstanceOf(GooglePlayBilling::class);
});

it('keeps the service account encrypted at rest', function () {
    app(BillingSettings::class)->set(BillingSettings::PLAY_ACCOUNT, (string) json_encode(['client_email' => 'play@x.test', 'private_key' => 'SECRET-KEY']));

    $stored = (string) PlatformSetting::query()->whereKey(BillingSettings::PLAY_ACCOUNT)->value('value');

    expect($stored)->not->toContain('SECRET-KEY')->not->toContain('play@x.test')
        ->and(app(BillingSettings::class)->playAccount()['private_key'])->toBe('SECRET-KEY');
});
