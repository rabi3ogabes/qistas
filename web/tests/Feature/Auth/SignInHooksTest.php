<?php

use App\Auth\SignInRecorder;
use App\Auth\TrustedDevice;
use App\Models\AuditLog;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/*
| The moments a sign-in record is written (plan deviation D4): one `success` per real sign-in or sign-up, on the website
| and in the phone app's API; none when a remember-me cookie brings a person back, none per request; one `failed` for a
| wrong password on an account that exists, at most once a minute per address. What a row holds is tested in
| SignInRecorderTest; this file tests when one is written, and that a record that cannot be written locks nobody out.
*/

const SIGN_IN_HOOKS_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const SIGN_IN_HOOKS_DART = 'Dart/3.5 (dart:io)';
const SIGN_IN_HOOKS_IP = '203.0.113.9';
const SIGN_IN_HOOKS_WRONG = 'Wrong!Passw0rd1';
const SIGN_IN_HOOKS_STORE_DOWN = 'The sign-in record store is down.';

/** @return array{0: User, 1: string} a workspace owner with two-step sign-in on, and the authenticator's secret */
function signInHooksTwoFactorOwner(): array
{
    $secret = app(Google2FA::class)->generateSecretKey();
    [$owner] = makeAccount([
        'two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345'])),
    ]);

    return [$owner, $secret];
}

/**
 * The website's sign-in form as the browser posts it: the time zone is the hidden field the page fills in.
 *
 * @param  array<string, mixed>  $extra
 */
function signInHooksWebLogin(User $user, string $password = TEST_PASSWORD, array $extra = []): TestResponse
{
    return test()->post('/login', array_merge(['email' => $user->email, 'password' => $password, 'timezone' => 'Asia/Riyadh'], $extra));
}

/**
 * The phone app's sign-in: what the person typed, and what the app reports about itself.
 *
 * @param  array<string, mixed>  $extra
 */
function signInHooksAppLogin(string $email, string $password = TEST_PASSWORD, array $extra = []): TestResponse
{
    return test()->withHeader('User-Agent', SIGN_IN_HOOKS_DART)->postJson('/api/v1/auth/login', array_merge([
        'email' => $email, 'password' => $password, 'device_name' => 'Layla’s tablet',
        'device' => ['type' => 'tablet', 'os' => 'android', 'model' => 'SM-X200', 'app_version' => '1.29.0'],
        'utc_offset' => 180,
    ], $extra));
}

/** @return array<string, mixed> the website's sign-up form */
function signInHooksWebSignUp(): array
{
    return [
        'name' => 'Layla Haddad', 'email' => 'layla@example.com', 'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD, 'terms' => '1', 'timezone' => 'Asia/Riyadh',
    ];
}

/** @return array<string, mixed> what the phone app's sign-up screen sends */
function signInHooksAppSignUp(): array
{
    return [
        'name' => 'Omar Saleh', 'email' => 'omar@example.com', 'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD, 'terms' => true, 'device_name' => 'Omar’s phone', 'utc_offset' => 180,
        'device' => ['type' => 'mobile', 'os' => 'ios', 'model' => 'iPhone15,2', 'app_version' => '1.29.0'],
    ];
}

/** @return list<string> every sign-in row, as "outcome/channel/method", oldest first */
function signInHooksRows(): array
{
    return SignInEvent::query()->orderBy('created_at')->get()
        ->map(fn (SignInEvent $row) => "{$row->outcome}/{$row->channel}/{$row->method}")
        ->all();
}

/** How often the broken recorder's problem was reported (other reports, such as a refused sign-in's, do not count). */
function signInHooksStoreDownReports(): int
{
    return collect(Exceptions::reported())->filter(fn (Throwable $e) => $e->getMessage() === SIGN_IN_HOOKS_STORE_DOWN)->count();
}

/** @return list<string> the property names a schema of docs/api/openapi.yaml lists, in order */
function signInHooksDocumented(string $schema): array
{
    $properties = [];
    $inside = false;

    foreach (file(base_path('../docs/api/openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === "    {$schema}:") {
            $inside = true;
        } elseif ($inside && preg_match('/^    \S/', $line)) {
            break;
        } elseif ($inside && preg_match('/^        ([a-z_]+):/', $line, $match)) {
            $properties[] = $match[1];
        }
    }

    return $properties;
}

beforeEach(function () {
    config(['qistas.geo_header' => 'CF-IPCountry', 'qistas.proxy_chain_allowed' => 2]);

    $this->withServerVariables(['REMOTE_ADDR' => SIGN_IN_HOOKS_IP])
        ->withHeaders(['User-Agent' => SIGN_IN_HOOKS_IPHONE, 'CF-IPCountry' => 'SA']);
});

describe('signing in on the website', function () {
    it('writes one success: the method, the channel, the device, the address and the proxy verdict', function () {
        [$user] = makeAccount();

        signInHooksWebLogin($user, extra: ['timezone' => 'Europe/Paris'])->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        expect(SignInEvent::query()->sole()->only([
            'user_id', 'method', 'channel', 'outcome', 'device_type', 'os', 'browser', 'app_version', 'device_name',
            'ip', 'ip_country', 'proxy', 'proxy_signals',
        ]))->toBe([
            'user_id' => $user->id, 'method' => 'password', 'channel' => 'web', 'outcome' => 'success',
            'device_type' => 'mobile', 'os' => 'iOS', 'browser' => 'Mobile Safari', 'app_version' => null, 'device_name' => null,
            'ip' => SIGN_IN_HOOKS_IP, 'ip_country' => 'SA', 'proxy' => 'possible', 'proxy_signals' => ['timezone_country_mismatch'],
        ]);
    });

    it('writes nothing when a remember-me cookie brings the person back, though Laravel calls that a login too', function () {
        [$user] = makeAccount();
        $response = signInHooksWebLogin($user, extra: ['remember' => '1']);
        $recaller = Auth::guard('web')->getRecallerName();
        $cookie = $response->getCookie($recaller);
        expect($cookie)->not->toBeNull();

        // Another visit: the session is gone and only the "Keep me signed in" cookie is left.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withCookie($recaller, $cookie->getValue())->get(config('fortify.home'))->assertOk();

        $this->assertAuthenticatedAs($user);
        // Laravel fired its login event for the cookie (the audit shows a second login), and still one sign-in.
        expect(AuditLog::where('action', 'login.succeeded')->where('user_id', $user->id)->count())->toBe(2)
            ->and(signInHooksRows())->toBe(['success/web/password']);
    });

    it('writes nothing for the pages a signed-in person opens: fifty of them, still one sign-in', function () {
        [$user] = makeAccount();
        signInHooksWebLogin($user)->assertRedirect(config('fortify.home'));

        foreach (range(1, 50) as $_) {
            $this->get(config('fortify.home'))->assertOk();
        }

        expect(signInHooksRows())->toBe(['success/web/password']);
    });
});

describe('two-step sign-in on the website', function () {
    it('writes nothing after the password, and one success once the code is right', function () {
        [$user, $secret] = signInHooksTwoFactorOwner();

        signInHooksWebLogin($user)->assertRedirect(route('two-factor.login'));
        expect(signInHooksRows())->toBe([]);

        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        expect(signInHooksRows())->toBe(['success/web/password'])
            ->and(SignInEvent::query()->sole()->only(['user_id', 'device_type', 'ip']))
            ->toBe(['user_id' => $user->id, 'device_type' => 'mobile', 'ip' => SIGN_IN_HOOKS_IP]);
    });

    it('writes one success for a recovery code instead of a code', function () {
        [$user] = signInHooksTwoFactorOwner();
        signInHooksWebLogin($user);

        $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-12345'])->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        expect(signInHooksRows())->toBe(['success/web/password']);
    });

    it('writes one success at the password on a device trusted to skip the code', function () {
        [$user] = signInHooksTwoFactorOwner();
        $this->withCookie(TrustedDevice::cookieName($user), app(TrustedDevice::class)->cookieFor($user)->getValue());

        signInHooksWebLogin($user)->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        expect(signInHooksRows())->toBe(['success/web/password']);
    });

    it('writes nothing for a wrong code', function () {
        [$user] = signInHooksTwoFactorOwner();
        signInHooksWebLogin($user);

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        expect(signInHooksRows())->toBe([]);
    });

    it('writes nothing when two-step sign-in is switched on from the security page: that is no sign-in', function () {
        $user = User::factory()->create();
        $sudo = fn () => $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()]);
        $sudo()->post('/user/two-factor-authentication');
        $secret = decrypt($user->fresh()->two_factor_secret);

        $sudo()->post('/user/confirmed-two-factor-authentication', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);

        expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue()
            ->and(signInHooksRows())->toBe([]);
    });
});

describe('signing up on the website', function () {
    it('writes one success, not two, though the new owner is signed in on the way', function () {
        $this->post('/register', signInHooksWebSignUp())->assertRedirect(config('fortify.home'));

        $user = User::where('email', 'layla@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        expect(signInHooksRows())->toBe(['success/web/password'])
            ->and(SignInEvent::query()->sole()->only(['user_id', 'device_type', 'ip', 'proxy']))
            ->toBe(['user_id' => $user->id, 'device_type' => 'mobile', 'ip' => SIGN_IN_HOOKS_IP, 'proxy' => 'not_detected']);
    });
});

describe('a wrong password on the website', function () {
    it('writes one failed record for an account that exists, and answers as for anyone', function () {
        [$user] = makeAccount();

        signInHooksWebLogin($user, SIGN_IN_HOOKS_WRONG)->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
        $row = SignInEvent::query()->sole();
        expect($row->only(['user_id', 'method', 'channel', 'outcome', 'device_type', 'ip']))->toBe([
            'user_id' => $user->id, 'method' => 'password', 'channel' => 'web', 'outcome' => 'failed',
            'device_type' => 'mobile', 'ip' => SIGN_IN_HOOKS_IP,
        ])->and(json_encode($row->getAttributes()))->not->toContain(SIGN_IN_HOOKS_WRONG)->not->toContain($user->email);
    });

    it('writes nothing for an e-mail no account has: there is nobody to write it for, and the address is never kept', function () {
        makeAccount();

        $this->post('/login', ['email' => 'nobody@example.com', 'password' => SIGN_IN_HOOKS_WRONG, 'timezone' => 'Asia/Riyadh'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        expect(SignInEvent::query()->count())->toBe(0);
    });

    it('writes nothing for a suspended account given the right password', function () {
        [$user] = makeAccount(['status' => 'suspended']);

        signInHooksWebLogin($user)->assertSessionHasErrors(['email' => __('Your account has been suspended. Please contact support.')]);

        $this->assertGuest();
        expect(SignInEvent::query()->count())->toBe(0)
            ->and(AuditLog::where('action', 'login.blocked')->where('user_id', $user->id)->count())->toBe(1);
    });

    it('writes one failed record for wrong passwords repeated from one address within a minute', function () {
        [$user] = makeAccount();

        foreach (range(1, 3) as $_) {
            signInHooksWebLogin($user, SIGN_IN_HOOKS_WRONG)->assertSessionHasErrors('email');
        }

        expect(signInHooksRows())->toBe(['failed/web/password']);
    });
});

describe('the phone app', function () {
    it('writes one success on the app channel, with the device the app reports and its offset from UTC', function () {
        [$user] = makeAccount();

        // Saudi Arabia is three hours ahead of UTC; a phone one hour ahead does not fit the address's country.
        signInHooksAppLogin($user->email, extra: ['utc_offset' => 60])->assertOk()->assertJsonStructure(['data' => ['token']]);

        expect(SignInEvent::query()->sole()->only([
            'user_id', 'method', 'channel', 'outcome', 'device_type', 'os', 'browser', 'app_version', 'device_name',
            'ip', 'ip_country', 'proxy', 'proxy_signals',
        ]))->toBe([
            'user_id' => $user->id, 'method' => 'password', 'channel' => 'app', 'outcome' => 'success',
            'device_type' => 'tablet', 'os' => 'android', 'browser' => null, 'app_version' => '1.29.0', 'device_name' => 'SM-X200',
            'ip' => SIGN_IN_HOOKS_IP, 'ip_country' => 'SA', 'proxy' => 'possible', 'proxy_signals' => ['timezone_country_mismatch'],
        ]);
    });

    it('writes once for two-step sign-in: nothing until the right code comes with the password', function () {
        [$user, $secret] = signInHooksTwoFactorOwner();

        signInHooksAppLogin($user->email)->assertUnprocessable()->assertJsonPath('error.code', 'two_factor_required');
        signInHooksAppLogin($user->email, extra: ['code' => '000000'])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_two_factor_code');
        expect(signInHooksRows())->toBe([]);

        signInHooksAppLogin($user->email, extra: ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

        expect(signInHooksRows())->toBe(['success/app/password']);
    });

    it('writes one failed record for a wrong password, on the app channel, and nothing for an unknown e-mail', function () {
        [$user] = makeAccount();

        signInHooksAppLogin($user->email, SIGN_IN_HOOKS_WRONG)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');
        signInHooksAppLogin('nobody@example.com', SIGN_IN_HOOKS_WRONG)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');

        expect(signInHooksRows())->toBe(['failed/app/password'])
            ->and(SignInEvent::query()->sole()->user_id)->toBe($user->id);
    });

    it('writes nothing for a suspended account given the right password', function () {
        [$user] = makeAccount(['status' => 'suspended']);

        signInHooksAppLogin($user->email)->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

        expect(SignInEvent::query()->count())->toBe(0);
    });

    it('writes one success for a sign-up from the app', function () {
        $this->withHeader('User-Agent', SIGN_IN_HOOKS_DART)->postJson('/api/v1/auth/register', signInHooksAppSignUp())->assertCreated();

        $user = User::where('email', 'omar@example.com')->sole();
        expect(signInHooksRows())->toBe(['success/app/password'])
            ->and(SignInEvent::query()->sole()->only(['user_id', 'device_type', 'os', 'device_name', 'app_version', 'proxy']))->toBe([
                'user_id' => $user->id, 'device_type' => 'mobile', 'os' => 'ios', 'device_name' => 'iPhone15,2',
                'app_version' => '1.29.0', 'proxy' => 'not_detected',
            ]);
    });

    it('never refuses a sign-in over a device report of the wrong shape', function () {
        [$user] = makeAccount();

        signInHooksAppLogin($user->email, extra: ['device' => 'tablet', 'timezone' => ['Asia/Riyadh'], 'utc_offset' => 'east'])->assertOk();

        expect(signInHooksRows())->toBe(['success/app/password'])
            ->and(SignInEvent::query()->sole()->only(['device_type', 'device_name', 'proxy']))
            ->toBe(['device_type' => 'unknown', 'device_name' => null, 'proxy' => 'not_detected']);
    });

    it('documents what the app may send about itself at sign-in and sign-up', function () {
        expect(signInHooksDocumented('LoginRequest'))->toContain('device', 'timezone', 'utc_offset')
            ->and(signInHooksDocumented('RegisterRequest'))->toContain('device', 'timezone', 'utc_offset')
            ->and(signInHooksDocumented('DeviceHint'))->toBe(['type', 'os', 'model', 'app_version']);
    });
});

describe('a record that cannot be written', function () {
    beforeEach(function () {
        Exceptions::fake();
        // SignInRecorder is final, so the fake is a binding that throws the moment the recorder is asked for.
        $this->app->bind(SignInRecorder::class, fn () => throw new RuntimeException(SIGN_IN_HOOKS_STORE_DOWN));
    });

    it('never stops a sign-in on the website, and the problem is reported', function () {
        [$user] = makeAccount();

        signInHooksWebLogin($user)->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        expect(signInHooksStoreDownReports())->toBe(1);
    });

    it('never stops a two-step sign-in on the website', function () {
        [$user, $secret] = signInHooksTwoFactorOwner();
        signInHooksWebLogin($user);

        $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
        expect(signInHooksStoreDownReports())->toBe(1);
    });

    it('never stops a sign-up on the website', function () {
        $this->post('/register', signInHooksWebSignUp())->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs(User::where('email', 'layla@example.com')->sole());
        expect(signInHooksStoreDownReports())->toBe(1);
    });

    it('never changes the answer to a wrong password', function () {
        [$user] = makeAccount();

        signInHooksWebLogin($user, SIGN_IN_HOOKS_WRONG)->assertSessionHasErrors(['email' => __('auth.failed')]);
        signInHooksAppLogin($user->email, SIGN_IN_HOOKS_WRONG)->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');

        $this->assertGuest();
        expect(signInHooksStoreDownReports())->toBe(2);
    });

    it('never stops the phone app signing in or signing up', function () {
        [$user] = makeAccount();

        signInHooksAppLogin($user->email)->assertOk()->assertJsonStructure(['data' => ['token']]);
        $this->withHeader('User-Agent', SIGN_IN_HOOKS_DART)->postJson('/api/v1/auth/register', signInHooksAppSignUp())->assertCreated();

        expect(signInHooksStoreDownReports())->toBe(2);
    });
});

it('lets the person in when the table of records is gone, and reports the database error', function () {
    Exceptions::fake();
    [$user] = makeAccount();
    Schema::drop('sign_in_events');

    signInHooksWebLogin($user)->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
    Exceptions::assertReported(QueryException::class);
})->group('db-errors'); // makes the database raise an error: left out when running against the PGlite stand-in
