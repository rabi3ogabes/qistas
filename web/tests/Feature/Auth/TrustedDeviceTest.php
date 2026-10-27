<?php

use App\Auth\TrustedDevice;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Cookie;

/** @return array{0: User, 1: string} an account with a confirmed second factor, and its secret */
function trustedDeviceAccount(array $user = []): array
{
    $secret = app(Google2FA::class)->generateSecretKey();
    [$account] = makeAccount(array_merge(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt($secret)], $user));

    return [$account, $secret];
}

/** Password, then a correct code from the authenticator. */
function signInWithCode(User $user, string $secret, array $extra = []): TestResponse
{
    test()->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

    return test()->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)] + $extra);
}

/** The remembered-device cookie the response sets, decrypted the way the browser's next request will carry it. */
function deviceCookieIn(TestResponse $response, User $user): ?Cookie
{
    return $response->getCookie(TrustedDevice::cookieName($user));
}

/** The security area asks for the password again; this session has just given it. */
function sudoAs(User $user)
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

function presentDevice(User $user, string $value): void
{
    test()->withCookie(TrustedDevice::cookieName($user), $value);
}

function trustedValueFor(User $user): string
{
    return app(TrustedDevice::class)->cookieFor($user)->getValue();
}

describe('signing in', function () {
    it('offers to remember the device on the code page, ticked', function () {
        [$user] = trustedDeviceAccount();
        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

        $this->get(route('two-factor.login'))
            ->assertOk()
            ->assertSee('name="remember_device"', false)
            ->assertSee('checked', false);
    });

    it('remembers the device for 30 days when the box is ticked', function () {
        [$user, $secret] = trustedDeviceAccount();

        $response = signInWithCode($user, $secret, ['remember_device' => '1'])->assertRedirect(config('fortify.home'));

        $cookie = deviceCookieIn($response, $user);
        expect($cookie)->not->toBeNull()
            ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(30)->subMinute()->getTimestamp())
            ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->addMinute()->getTimestamp())
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and(AuditLog::where('action', 'two_factor.device_trusted')->where('user_id', $user->id)->count())->toBe(1);
        $this->assertAuthenticatedAs($user);
    });

    it('also remembers the device after a recovery code', function () {
        [$user] = trustedDeviceAccount(['two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345']))]);
        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

        $response = $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-12345', 'remember_device' => '1']);

        expect(deviceCookieIn($response, $user))->not->toBeNull();
    });

    it('does not remember the device when the box is unticked', function () {
        [$user, $secret] = trustedDeviceAccount();

        $response = signInWithCode($user, $secret)->assertRedirect(config('fortify.home'));

        expect(deviceCookieIn($response, $user))->toBeNull()
            ->and(AuditLog::where('action', 'two_factor.device_trusted')->count())->toBe(0);
    });

    it('skips the code on a remembered device', function () {
        [$user, $secret] = trustedDeviceAccount();
        $value = deviceCookieIn(signInWithCode($user, $secret, ['remember_device' => '1']), $user)->getValue();
        auth()->logout();
        $this->flushSession();

        presentDevice($user, $value);
        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(config('fortify.home'));

        $this->assertAuthenticatedAs($user);
    });

    it('still checks the password on a remembered device', function () {
        [$user] = trustedDeviceAccount();
        presentDevice($user, trustedValueFor($user));

        $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    });

    it('does not let a device remembered for one person skip the code for another', function () {
        [$first] = trustedDeviceAccount();
        [$second] = trustedDeviceAccount();
        presentDevice($second, trustedValueFor($first));

        $this->post('/login', ['email' => $second->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    });

    it('forgets the device after 30 days', function () {
        [$user] = trustedDeviceAccount();
        $value = trustedValueFor($user);

        $this->travel(31)->days();
        presentDevice($user, $value);

        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));
    });

    it('forgets the device when the password changes', function () {
        [$user] = trustedDeviceAccount();
        $value = trustedValueFor($user);
        $user->forceFill(['password' => Hash::make('An0ther!Passw0rd')])->save();
        presentDevice($user, $value);

        $this->post('/login', ['email' => $user->email, 'password' => 'An0ther!Passw0rd'])->assertRedirect(route('two-factor.login'));
    });

    it('forgets the device when two-factor authentication gets a new secret', function () {
        [$user] = trustedDeviceAccount();
        $value = trustedValueFor($user);
        $user->forceFill(['two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey())])->save();
        presentDevice($user, $value);

        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));
    });

    it('refuses a value that was not made by Qistas', function (string $value) {
        [$user] = trustedDeviceAccount();
        presentDevice($user, $value);

        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));
    })->with([
        'nonsense' => ['trust-me'],
        'a far expiry with no valid signature' => [(string) now()->addYears(5)->getTimestamp().'.'.str_repeat('a', 64)],
        'empty' => [''],
    ]);

    it('refuses a genuine signature moved to a later expiry', function () {
        [$user] = trustedDeviceAccount();
        [, $signature] = explode('.', trustedValueFor($user), 2);
        presentDevice($user, now()->addYears(5)->getTimestamp().'.'.$signature);

        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));
    });

    it('lets an admin who signed in on a remembered device into the admin area', function () {
        Route::middleware(['web', 'auth', 'admin'])->get('/_test/trusted-admin', fn () => 'console');
        [$admin] = trustedDeviceAccount(['platform_role' => 'admin']);
        presentDevice($admin, trustedValueFor($admin));
        $this->post('/login', ['email' => $admin->email, 'password' => TEST_PASSWORD]);

        $this->get('/_test/trusted-admin')->assertOk()->assertSee('console');
    });
});

describe('the security page', function () {
    it('remembers the device when setup is confirmed with the box ticked', function () {
        $user = User::factory()->create();
        sudoAs($user)->post('/user/two-factor-authentication');
        $secret = decrypt($user->fresh()->two_factor_secret);

        $response = sudoAs($user->fresh())->post('/user/confirmed-two-factor-authentication', [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
            'remember_device' => '1',
        ]);

        expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue()
            ->and(deviceCookieIn($response, $user))->not->toBeNull();
    });

    it('shows how long this device is remembered and offers to forget every device', function () {
        $user = User::factory()->withTwoFactor()->create();
        presentDevice($user, trustedValueFor($user));

        sudoAs($user)->get(route('security'))
            ->assertOk()
            ->assertSee(__('This device skips the code until :date.', ['date' => now()->addDays(30)->translatedFormat('j F Y')]))
            ->assertSee('action="'.route('security.trusted-devices.forget').'"', false);
    });

    it('forgets every remembered device on request', function () {
        [$user] = trustedDeviceAccount();
        $value = trustedValueFor($user);

        sudoAs($user)->post(route('security.trusted-devices.forget'))->assertRedirect(route('security'));
        auth()->logout();
        $this->flushSession();
        presentDevice($user, $value);

        $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));
        expect(AuditLog::where('action', 'two_factor.devices_forgotten')->where('user_id', $user->id)->count())->toBe(1);
    });

    it('offers a new key while setup is not yet confirmed', function () {
        $user = User::factory()->create();
        sudoAs($user)->post('/user/two-factor-authentication');

        sudoAs($user->fresh())->get(route('security'))
            ->assertOk()
            ->assertSee('action="'.route('security.two-factor.restart').'"', false);
    });

    it('replaces an unconfirmed key with a new one', function () {
        $user = User::factory()->create();
        sudoAs($user)->post('/user/two-factor-authentication');
        $old = decrypt($user->fresh()->two_factor_secret);

        sudoAs($user->fresh())->post(route('security.two-factor.restart'))
            ->assertRedirect(route('security'))
            ->assertSessionHas('status');

        $fresh = $user->fresh();
        expect(decrypt($fresh->two_factor_secret))->not->toBe($old)
            ->and($fresh->hasConfirmedTwoFactor())->toBeFalse()
            ->and(AuditLog::where('action', 'two_factor.setup_restarted')->where('user_id', $user->id)->count())->toBe(1);
    });

    it('never replaces a confirmed key', function () {
        $user = User::factory()->withTwoFactor()->create();
        $secret = $user->two_factor_secret;

        sudoAs($user)->post(route('security.two-factor.restart'))->assertRedirect(route('security'));

        expect($user->fresh()->two_factor_secret)->toBe($secret);
    });

    it('asks for the password before making a new key', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('security.two-factor.restart'))->assertRedirect(route('password.confirm'));
    });
});
