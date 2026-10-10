<?php

use App\Models\PushToken;
use App\Notifications\Push\FcmPushSender;
use App\Notifications\Push\LogPushSender;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Real pushes go through Firebase Cloud Messaging (HTTP v1): a short-lived access token signed with the project's
| service account, then one message per phone. Without the owner's Firebase key the app only writes pushes to its log.
*/

/** A throwaway service account with a fresh RSA key, as Google hands out. */
function serviceAccount(): array
{
    // PHP on Windows finds no OpenSSL config of its own; it ships one beside the binary.
    $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($bundled) ? ['config' => $bundled] : []);
    $key = openssl_pkey_new($options);
    openssl_pkey_export($key, $pem, null, $options);

    return [
        'type' => 'service_account', 'project_id' => 'qistas-test', 'private_key' => $pem,
        'client_email' => 'push@qistas-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
    ];
}

function phoneToken(string $token = 'phone-1'): PushToken
{
    [$owner, $tenant] = owner();

    return app(CurrentTenant::class)->use($tenant, fn () => PushToken::create(['user_id' => $owner->id, 'token' => $token, 'platform' => 'android']));
}

it('sends one message per phone with a token signed by the service account', function () {
    $account = serviceAccount();
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/qistas-test/messages/1']),
    ]);

    $sent = (new FcmPushSender($account))->send(phoneToken(), new PushMessage('daily_digest', 'Today’s collections', 'Due today: 1', ['route' => '/']));

    expect($sent)->toBeTrue();
    Http::assertSent(function (Request $request) use ($account) {
        if (! str_contains($request->url(), 'oauth2')) {
            return false;
        }
        [$header, $claims, $signature] = explode('.', $request['assertion']);
        $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $verified = openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/')), openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_get_private($account['private_key']))['key']), OPENSSL_ALGO_SHA256);

        return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $verified === 1
            && $decoded['iss'] === $account['client_email'] && $decoded['scope'] === 'https://www.googleapis.com/auth/firebase.messaging';
    });
    Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/qistas-test/messages:send'
        && $request->hasHeader('Authorization', 'Bearer ya29.test')
        && $request['message']['token'] === 'phone-1'
        && $request['message']['notification'] === ['title' => 'Today’s collections', 'body' => 'Due today: 1']
        && $request['message']['data'] === ['type' => 'daily_digest', 'route' => '/']);
});

it('forgets a phone Firebase says is gone', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);
    $token = phoneToken();

    $sent = (new FcmPushSender(serviceAccount()))->send($token, new PushMessage('daily_digest', 'T', 'B'));

    expect($sent)->toBeFalse()->and(PushToken::withoutGlobalScopes()->find($token->id)->revoked_at)->not->toBeNull();
});

it('keeps the phone after a passing failure', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
    ]);
    $token = phoneToken();

    expect((new FcmPushSender(serviceAccount()))->send($token, new PushMessage('daily_digest', 'T', 'B')))->toBeFalse()
        ->and(PushToken::withoutGlobalScopes()->find($token->id)->revoked_at)->toBeNull();
});

it('only writes pushes to the log until the owner gives the Firebase key', function () {
    config(['qistas.push.fcm_credentials' => null]);
    expect(app(PushSender::class))->toBeInstanceOf(LogPushSender::class);

    config(['qistas.push.fcm_credentials' => base64_encode(json_encode(serviceAccount()))]);
    app()->forgetInstance(PushSender::class);
    expect(app(PushSender::class))->toBeInstanceOf(FcmPushSender::class);
});
