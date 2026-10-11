<?php

use App\Billing\BillingSettings;
use App\Billing\Store\FakeStoreBilling;
use App\Billing\Store\StoreBilling;
use App\Billing\Store\StorePurchase;
use App\Billing\StoreSubscriptions;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\StoreEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Win Plan PP13: Pro through Google Play. The app sends the purchase token; the server asks Google (never trusting the
| phone), puts the workspace on the plan the product buys until the period Google reports, and acknowledges the
| purchase. Google's real-time notifications then renew, cancel, refund, hold and expire it; each is applied once, by
| its message id, and only when Google signed it. The plan belongs to the workspace, not the phone.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

function playStore(): FakeStoreBilling
{
    switchOn(Feature::StoreBilling);
    $settings = app(BillingSettings::class);
    $settings->set(BillingSettings::PLAY_PACKAGE, 'app.qistas');
    $settings->set(BillingSettings::PLAY_ACCOUNT, (string) json_encode(['client_email' => 'play@qistas-test.iam.gserviceaccount.com', 'private_key' => 'unused']));
    $settings->set(BillingSettings::PLAY_PRODUCTS, (string) json_encode(['pro' => 'qistas_pro']));
    $settings->set(BillingSettings::PUSH_AUDIENCE, 'https://qistas.test/webhooks/google-play');
    $settings->set(BillingSettings::PUSH_EMAIL, 'pubsub@qistas-test.iam.gserviceaccount.com');

    $store = new FakeStoreBilling;
    app()->instance(StoreBilling::class, $store);

    return $store;
}

/** @param  array<string, mixed>  $overrides */
function playPurchase(?Tenant $for = null, array $overrides = []): StorePurchase
{
    return new StorePurchase(...$overrides + [
        'productId' => 'qistas_pro',
        'state' => 'active',
        'expiresAt' => CarbonImmutable::parse('2026-11-11 09:00:00'),
        'autoRenew' => true,
        'acknowledged' => false,
        'accountRef' => $for === null ? null : StoreSubscriptions::accountRef($for),
    ]);
}

function verifyPurchase(string $token = 'play-token-1', string $product = 'qistas_pro'): TestResponse
{
    return test()->postJson('/api/v1/billing/play/verify', ['product_id' => $product, 'purchase_token' => $token]);
}

function subscriptionOf(Tenant $tenant): Subscription
{
    return Subscription::query()->where('tenant_id', $tenant->id)->sole();
}

describe('verifying a purchase', function () {
    it('puts the workspace on Pro until the period Google reports, and acknowledges the purchase', function () {
        $store = playStore();
        [$owner, $tenant] = apiOwner();
        $store->record('play-token-1', playPurchase($tenant));

        verifyPurchase()->assertOk()
            ->assertJsonPath('data.plan.key', 'pro')->assertJsonPath('data.until', '2026-11-11')
            ->assertJsonPath('data.source', 'play')->assertJsonPath('data.auto_renew', true);

        $subscription = subscriptionOf($tenant);
        expect($tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and($subscription->purchase_token_hash)->toBe(hash('sha256', 'play-token-1'))
            ->and($subscription->store_product_id)->toBe('qistas_pro')
            ->and($subscription->status)->toBe('active')
            ->and($store->acknowledged)->toBe(['play-token-1'])
            ->and(AuditLog::query()->where('action', 'billing.store_verified')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->count())->toBe(1);
    });

    it('changes nothing when the same token is sent again (a restore on a new phone)', function () {
        $store = playStore();
        [, $tenant] = apiOwner();
        $store->record('play-token-1', playPurchase($tenant));

        verifyPurchase()->assertOk();
        $before = subscriptionOf($tenant)->only(['plan_id', 'status', 'current_period_end', 'purchase_token_hash']);
        verifyPurchase()->assertOk()->assertJsonPath('data.plan.key', 'pro');

        expect(subscriptionOf($tenant)->only(['plan_id', 'status', 'current_period_end', 'purchase_token_hash']))->toEqual($before)
            ->and(Subscription::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and($store->acknowledged)->toBe(['play-token-1'])
            ->and(AuditLog::query()->where('action', 'billing.store_verified')->count())->toBe(1);
    });

    it('unlocks nothing for a token Google does not know, a pending payment or a lapsed purchase', function () {
        $store = playStore();
        [, $tenant] = apiOwner();
        $store->record('pending', playPurchase($tenant, ['state' => 'pending']));
        $store->record('lapsed', playPurchase($tenant, ['state' => 'expired', 'expiresAt' => CarbonImmutable::parse('2026-10-01')]));

        verifyPurchase('made-up')->assertStatus(422)->assertJsonPath('error.code', 'purchase_invalid');
        verifyPurchase('pending')->assertStatus(422)->assertJsonPath('error.code', 'purchase_pending');
        verifyPurchase('lapsed')->assertStatus(422)->assertJsonPath('error.code', 'purchase_inactive');

        expect($tenant->fresh()->currentPlan()->key)->toBe('free')->and($store->acknowledged)->toBe([]);
    });

    it('unlocks nothing for a product this platform does not sell', function () {
        $store = playStore();
        [, $tenant] = apiOwner();
        $store->record('play-token-1', playPurchase($tenant, ['productId' => 'someone_elses_pro']));

        verifyPurchase('play-token-1', 'someone_elses_pro')->assertStatus(422)->assertJsonPath('error.code', 'purchase_invalid');
        // Nor when the phone names one product and Google says the token bought another.
        verifyPurchase('play-token-1', 'qistas_pro')->assertStatus(422)->assertJsonPath('error.code', 'purchase_invalid');

        expect($tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('refuses a purchase made for another workspace, or already attached to one', function () {
        $store = playStore();
        $other = workspaceOn();
        [, $tenant] = apiOwner();
        $store->record('theirs', playPurchase($other));
        $store->record('shared', playPurchase());

        verifyPurchase('theirs')->assertStatus(409)->assertJsonPath('error.code', 'purchase_elsewhere');

        $other->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addMonth(), ['source' => 'play', 'purchase_token_hash' => hash('sha256', 'shared')]);
        verifyPurchase('shared')->assertStatus(409)->assertJsonPath('error.code', 'purchase_elsewhere');

        expect($tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('is for the owner only, while the platform has it switched on', function () {
        $store = playStore();
        [, $tenant] = apiOwner();
        $store->record('play-token-1', playPurchase($tenant));
        apiMember('manager', $tenant);

        verifyPurchase()->assertForbidden();

        apiOwner();
        app(FeatureControl::class)->setState(Feature::StoreBilling, PlatformState::Off, null, null);
        verifyPurchase()->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });

    it('tells the app what it can buy and the account reference to buy it with', function () {
        playStore();
        [, $tenant] = apiOwner();

        $this->getJson('/api/v1/billing')->assertOk()
            ->assertJsonPath('data.plan.key', 'free')->assertJsonPath('data.can_buy', true)
            ->assertJsonPath('data.store.available', true)
            ->assertJsonPath('data.store.account_ref', StoreSubscriptions::accountRef($tenant))
            ->assertJsonPath('data.store.products', [['plan_key' => 'pro', 'product_id' => 'qistas_pro']]);

        app(FeatureControl::class)->setState(Feature::StoreBilling, PlatformState::Off, null, null);
        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.store.available', false)->assertJsonPath('data.store.products', []);
    });

    it('offers nothing to buy until Google Play is set up', function () {
        switchOn(Feature::StoreBilling);
        apiOwner();

        $this->getJson('/api/v1/billing')->assertOk()->assertJsonPath('data.store.available', false);
    });
});

/** A key and certificate standing in for Google's, served where Google publishes its certificates. */
function googleSigner(): array
{
    static $signer = null;
    if ($signer !== null) {
        return $signer;
    }
    // PHP on Windows finds no OpenSSL config of its own; it ships one beside the binary.
    $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'] + (is_file($bundled) ? ['config' => $bundled] : []);
    $key = openssl_pkey_new($options);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'google-test'], $key, $options), null, $key, 2, $options);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $keyPem, null, $options);

    return $signer = ['kid' => 'google-key-1', 'key' => $keyPem, 'certificate' => $certificatePem];
}

/** @param  array<string, mixed>  $claims */
function googleToken(array $claims = []): string
{
    $signer = googleSigner();
    $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
    $unsigned = $encode(['alg' => 'RS256', 'kid' => $signer['kid'], 'typ' => 'JWT']).'.'.$encode($claims + [
        'iss' => 'https://accounts.google.com', 'aud' => 'https://qistas.test/webhooks/google-play',
        'email' => 'pubsub@qistas-test.iam.gserviceaccount.com', 'email_verified' => true,
        'iat' => time(), 'exp' => time() + 3600,
    ]);
    openssl_sign($unsigned, $signature, $signer['key'], OPENSSL_ALGO_SHA256);

    return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}

/** @param  array<string, mixed>  $notification  one of subscriptionNotification, voidedPurchaseNotification, testNotification */
function playNotification(array $notification, string $messageId = 'msg-1', ?string $token = null): TestResponse
{
    Http::fake(['www.googleapis.com/oauth2/v1/certs' => Http::response([googleSigner()['kid'] => googleSigner()['certificate']])]);
    $data = $notification + ['version' => '1.0', 'packageName' => 'app.qistas', 'eventTimeMillis' => (string) (time() * 1000)];

    return test()->withHeader('Authorization', 'Bearer '.($token ?? googleToken()))->postJson('/webhooks/google-play', [
        'message' => ['data' => base64_encode((string) json_encode($data)), 'messageId' => $messageId, 'publishTime' => now()->toIso8601String()],
        'subscription' => 'projects/qistas-test/subscriptions/play',
    ]);
}

/** Google's notification types: 2 renewed, 3 canceled, 5 on hold, 6 in grace, 12 revoked, 13 expired. */
function subscriptionChanged(int $type, string $token = 'play-token-1'): array
{
    return ['subscriptionNotification' => ['version' => '1.0', 'notificationType' => $type, 'purchaseToken' => $token, 'subscriptionId' => 'qistas_pro']];
}

/** @return array{0: FakeStoreBilling, 1: Tenant} a workspace on Pro through Google Play until 11 November */
function onPlay(): array
{
    $store = playStore();
    [, $tenant] = apiOwner();
    $store->record('play-token-1', playPurchase($tenant));
    verifyPurchase()->assertOk();

    return [$store, $tenant];
}

describe('Google’s notifications', function () {
    it('renews to the period end Google reports', function () {
        [$store, $tenant] = onPlay();
        $store->record('play-token-1', playPurchase($tenant, ['expiresAt' => CarbonImmutable::parse('2026-12-11 09:00:00'), 'acknowledged' => true]));

        playNotification(subscriptionChanged(2))->assertNoContent();

        expect(subscriptionOf($tenant)->current_period_end->toDateString())->toBe('2026-12-11')
            ->and(StoreEvent::query()->where('event_id', 'msg-1')->sole()->outcome)->toBe('renewed');
    });

    it('applies each message once, however often Google sends it', function () {
        [$store, $tenant] = onPlay();
        $store->record('play-token-1', playPurchase($tenant, ['expiresAt' => CarbonImmutable::parse('2026-12-11 09:00:00'), 'acknowledged' => true]));
        playNotification(subscriptionChanged(2), 'msg-7')->assertNoContent();

        // Sent again later, when Google would say something else: the repeat is recognised and nothing moves.
        $store->record('play-token-1', playPurchase($tenant, ['state' => 'expired', 'acknowledged' => true]));
        playNotification(subscriptionChanged(2), 'msg-7')->assertNoContent();

        expect(subscriptionOf($tenant)->status)->toBe('active')
            ->and(subscriptionOf($tenant)->current_period_end->toDateString())->toBe('2026-12-11')
            ->and(StoreEvent::query()->count())->toBe(1);
    });

    it('keeps Pro to the end of the period after a cancellation, and ends it when Google says it expired', function () {
        [$store, $tenant] = onPlay();
        $store->record('play-token-1', playPurchase($tenant, ['state' => 'canceled', 'autoRenew' => false, 'acknowledged' => true]));

        playNotification(subscriptionChanged(3), 'msg-1')->assertNoContent();
        expect(subscriptionOf($tenant)->auto_renew)->toBeFalse()->and($tenant->fresh()->currentPlan()->key)->toBe('pro');

        $store->record('play-token-1', playPurchase($tenant, ['state' => 'expired', 'autoRenew' => false, 'acknowledged' => true]));
        playNotification(subscriptionChanged(13), 'msg-2')->assertNoContent();

        expect(subscriptionOf($tenant)->status)->toBe('expired')->and($tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('ends Pro at once on a refund', function () {
        [$store, $tenant] = onPlay();
        $store->record('play-token-1', playPurchase($tenant, ['state' => 'revoked', 'acknowledged' => true]));

        playNotification(subscriptionChanged(12))->assertNoContent();

        expect(subscriptionOf($tenant)->status)->toBe('canceled')->and($tenant->fresh()->currentPlan()->key)->toBe('free')
            ->and(StoreEvent::query()->sole()->outcome)->toBe('refunded');
    });

    it('ends Pro at once when Google voids the purchase', function () {
        [, $tenant] = onPlay();

        playNotification(['voidedPurchaseNotification' => ['purchaseToken' => 'play-token-1', 'orderId' => 'GPA.1', 'productType' => 1, 'refundType' => 1]])->assertNoContent();

        expect($tenant->fresh()->currentPlan()->key)->toBe('free')->and(StoreEvent::query()->sole()->outcome)->toBe('refunded');
    });

    it('keeps Pro through Google’s grace period and stops it while the subscription is on hold', function () {
        [$store, $tenant] = onPlay();
        $store->record('play-token-1', playPurchase($tenant, ['state' => 'grace', 'acknowledged' => true]));
        playNotification(subscriptionChanged(6), 'msg-1')->assertNoContent();
        expect(subscriptionOf($tenant)->status)->toBe('past_due')->and($tenant->fresh()->currentPlan()->key)->toBe('pro');

        $store->record('play-token-1', playPurchase($tenant, ['state' => 'hold', 'acknowledged' => true]));
        playNotification(subscriptionChanged(5), 'msg-2')->assertNoContent();
        expect(subscriptionOf($tenant)->status)->toBe('on_hold')->and($tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('refuses a message Google did not sign for this endpoint, and changes nothing', function () {
        [$store, $tenant] = onPlay();
        $store->record('play-token-1', playPurchase($tenant, ['state' => 'revoked', 'acknowledged' => true]));

        playNotification(subscriptionChanged(12), 'msg-1', 'not-a-token')->assertForbidden();
        playNotification(subscriptionChanged(12), 'msg-2', googleToken(['aud' => 'https://elsewhere.test/hook']))->assertForbidden();
        playNotification(subscriptionChanged(12), 'msg-3', googleToken(['email' => 'stranger@evil.test']))->assertForbidden();
        playNotification(subscriptionChanged(12), 'msg-4', googleToken(['exp' => time() - 600]))->assertForbidden();
        $forged = googleToken();
        playNotification(subscriptionChanged(12), 'msg-5', substr($forged, 0, -6).'AAAAAA')->assertForbidden();

        expect($tenant->fresh()->currentPlan()->key)->toBe('pro')->and(StoreEvent::query()->count())->toBe(0);
    });

    it('notes and ignores a purchase it has never seen, or another app’s message', function () {
        [$store, $tenant] = onPlay();
        $store->record('unknown-token', playPurchase(null, ['acknowledged' => true]));

        playNotification(subscriptionChanged(2, 'unknown-token'), 'msg-1')->assertNoContent();
        playNotification(['packageName' => 'com.other.app'] + subscriptionChanged(12), 'msg-2')->assertNoContent();
        playNotification(['testNotification' => ['version' => '1.0']], 'msg-3')->assertNoContent();

        expect($tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and(StoreEvent::query()->orderBy('event_id')->pluck('outcome')->all())->toBe(['unknown', 'ignored', 'test']);
    });

    it('asks Google to send it again when Google cannot be reached, then applies it', function () {
        [$store, $tenant] = onPlay();
        $store->unreachable = true;

        playNotification(subscriptionChanged(13), 'msg-1')->assertStatus(503);
        expect($tenant->fresh()->currentPlan()->key)->toBe('pro');

        $store->unreachable = false;
        $store->record('play-token-1', playPurchase($tenant, ['state' => 'expired', 'acknowledged' => true]));
        playNotification(subscriptionChanged(13), 'msg-1')->assertNoContent();

        expect($tenant->fresh()->currentPlan()->key)->toBe('free')->and(StoreEvent::query()->sole()->outcome)->toBe('expired');
    });
});
