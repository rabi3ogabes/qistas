<?php

use App\Models\Contract;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Layla Haddad', 'email' => 'Layla@Example.com', 'password' => TEST_PASSWORD, 'password_confirmation' => TEST_PASSWORD,
        'business_name' => 'Al-Fares Electronics', 'country' => 'SA', 'terms' => true, 'device_name' => 'Layla’s phone',
    ], $overrides);
}

function twoFactorUser(?string $secret = null, array $recovery = []): array
{
    $secret ??= app(Google2FA::class)->generateSecretKey();
    [$user, $tenant] = makeAccount([
        'two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode($recovery)),
    ]);

    return [$user, $tenant, $secret];
}

beforeEach(fn () => RateLimiter::clear('api-login'));

describe('registering', function () {
    it('opens a free account and returns a token for it', function () {
        $response = $this->postJson('/api/v1/auth/register', registerPayload())->assertCreated();

        $response->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'layla@example.com')
            ->assertJsonPath('data.tenant.name', 'Al-Fares Electronics')
            ->assertJsonPath('data.tenant.currency', 'SAR')
            ->assertJsonPath('data.tenant.role', 'owner')
            ->assertJsonPath('data.plan.key', 'free')
            ->assertJsonPath('data.entitlements.customers.limit', 20)
            ->assertJsonPath('data.entitlements.customers.used', 0);
        expect($response->json('data.token'))->toMatch('/^\d+\|qst_\w+$/')->and($response->json('data.expires_at'))->not->toBeNull();

        $user = User::where('email', 'layla@example.com')->sole();
        expect($user->tenants()->count())->toBe(1)->and($user->tokens()->count())->toBe(1);
    });

    it('lets the new token be used straight away', function () {
        $token = $this->postJson('/api/v1/auth/register', registerPayload())->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.name', 'Layla Haddad');
    });

    it('asks for a verification e-mail, queued', function () {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', registerPayload())->assertCreated();

        Notification::assertSentTo(User::where('email', 'layla@example.com')->sole(), VerifyEmailQueued::class);
    });

    it('applies the password policy and every other rule, creating nothing', function (array $changes, string $field) {
        $this->postJson('/api/v1/auth/register', registerPayload($changes))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors($field, 'error.fields');
        expect(User::count())->toBe(0)->and(Tenant::count())->toBe(0);
    })->with([
        'a weak password' => [['password' => 'password', 'password_confirmation' => 'password'], 'password'],
        'passwords that differ' => [['password_confirmation' => 'Different!Passw0rd'], 'password'],
        'no e-mail' => [['email' => ''], 'email'],
        'a malformed e-mail' => [['email' => 'not-an-email'], 'email'],
        'no business name' => [['business_name' => ''], 'business_name'],
        'a country that is not a code' => [['country' => 'Saudi'], 'country'],
        'terms not accepted' => [['terms' => false], 'terms'],
    ]);

    it('refuses an e-mail that is already registered', function () {
        owner()[0]->forceFill(['email' => 'layla@example.com'])->save();

        $this->postJson('/api/v1/auth/register', registerPayload())->assertUnprocessable()->assertJsonValidationErrors('email', 'error.fields');
    });

    it('is rate limited per address', function () {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/register', registerPayload(['email' => "p{$i}@example.com", 'password' => '']))->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/register', registerPayload())->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
    });
});

describe('signing in', function () {
    it('returns a token, the account and what the plan allows', function () {
        [$user, $tenant] = makeAccount(['name' => 'Omar'], ['currency' => 'AED']);
        asTenant($tenant, fn () => customerIn($tenant));

        $response = $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => TEST_PASSWORD, 'device_name' => 'Pixel 9'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Omar')
            ->assertJsonPath('data.tenant.currency', 'AED')
            ->assertJsonPath('data.entitlements.customers.used', 1);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        expect($token->name)->toBe('Pixel 9')->and($token->abilities)->toBe(['app'])->and($token->expires_at->isFuture())->toBeTrue();
    });

    it('does not say which of e-mail and password was wrong', function () {
        [$user] = makeAccount();

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1'])->assertUnauthorized();
        $unknownEmail = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => TEST_PASSWORD])->assertUnauthorized();

        expect($wrongPassword->json())->toBe($unknownEmail->json())->and($wrongPassword->json('error.code'))->toBe('invalid_credentials')
            ->and($wrongPassword->json('error.message'))->toBe(trans('auth.failed'));
    });

    it('refuses a suspended account with a clear reason', function () {
        [$user] = makeAccount();
        $user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD])
            ->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
        expect($user->tokens()->count())->toBe(0);
    });

    it('needs both fields', function () {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com'])->assertUnprocessable()->assertJsonValidationErrors('password', 'error.fields');
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors('email', 'error.fields');
    });

    it('stops guessing: the sixth attempt in a minute is refused', function () {
        [$user] = makeAccount();

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited');
    });
});

describe('two-factor authentication', function () {
    it('asks for a code, and does not hand out a token before it', function () {
        [$user] = twoFactorUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD])
            ->assertUnprocessable()->assertJsonPath('error.code', 'two_factor_required');
        expect($user->tokens()->count())->toBe(0);
    });

    it('accepts a valid code', function () {
        [$user, , $secret] = twoFactorUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertOk()->assertJsonStructure(['data' => ['token']]);
    });

    it('refuses a wrong code', function () {
        [$user] = twoFactorUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD, 'code' => '000000'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_two_factor_code');
        expect($user->tokens()->count())->toBe(0);
    });

    it('accepts a recovery code once', function () {
        [$user] = twoFactorUser(recovery: ['abcde-12345', 'fghij-67890']);
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TEST_PASSWORD, 'recovery_code' => 'abcde-12345']);

        $login()->assertOk();
        $login()->assertUnauthorized()->assertJsonPath('error.code', 'invalid_two_factor_code');
    });

    it('does not reveal that a code is needed to someone who does not know the password', function () {
        [$user] = twoFactorUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
    });
});

describe('a token', function () {
    it('stops working when it expires', function () {
        [$user] = makeAccount();
        $token = $user->createToken('phone', ['app'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    });

    it('is required', function () {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
        $this->withToken('qst_not-a-real-token')->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('is not replaced by a browser session', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('stops working while the account is suspended', function () {
        [$user] = makeAccount();
        $token = $user->createToken('phone', ['app'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $user->forceFill(['status' => 'suspended'])->save();
        $this->app['auth']->forgetGuards(); // a new request: nothing remembered from the last one

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    });

    it('stops working once its workspace is suspended', function () {
        [$user, $tenant] = makeAccount();
        $token = $user->createToken('phone', ['app'])->plainTextToken;
        $tenant->forceFill(['status' => 'suspended'])->save();

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'account_suspended');
    });
});

describe('signing out', function () {
    it('ends just this device’s token', function () {
        [$user] = makeAccount();
        $phone = $user->createToken('phone', ['app'])->plainTextToken;
        $tablet = $user->createToken('tablet', ['app'])->plainTextToken;

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        expect($user->tokens()->pluck('name')->all())->toBe(['tablet']);
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
    });

    it('ends every app token of the person, but not an API access token they made on purpose', function () {
        [$user] = makeAccount();
        $phone = $user->createToken('phone', ['app'])->plainTextToken;
        $user->createToken('tablet', ['app']);
        $user->createToken('accounting export', ['integration']);

        $this->withToken($phone)->postJson('/api/v1/auth/logout-all')->assertNoContent();

        expect($user->tokens()->pluck('name')->all())->toBe(['accounting export']);
    });
});

it('does not let another workspace’s token see this one’s data', function () {
    [, $mine] = apiOwner();
    $contract = openContract($mine);
    [$other] = makeAccount();
    $this->app['auth']->forgetGuards();
    $token = $other->createToken('phone', ['app'])->plainTextToken;

    $this->withToken($token)->getJson("/api/v1/contracts/{$contract->id}")->assertNotFound();
    expect(asTenant($mine, fn () => Contract::count()))->toBe(1)->and(Plan::count())->toBeGreaterThan(0);
});
