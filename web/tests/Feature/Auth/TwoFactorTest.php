<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use PragmaRX\Google2FA\Google2FA;

it('asks for the second factor before signing in a user who has one', function () {
    [$user] = makeAccount(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);

    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});

it('signs the user in after a valid authenticator code', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    [$user] = makeAccount(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt($secret)]);
    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

    $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
});

it('refuses a wrong authenticator code', function () {
    [$user] = makeAccount(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey())]);
    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('accepts a recovery code exactly once', function () {
    [$user] = makeAccount([
        'two_factor_confirmed_at' => now(),
        'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345', 'fghij-67890'])),
    ]);
    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);
    $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-12345'])->assertRedirect(config('fortify.home'));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);
    $this->post('/two-factor-challenge', ['recovery_code' => 'abcde-12345'])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

it('records enabling and disabling the second factor in the audit log', function () {
    [$user] = makeAccount();

    event(new TwoFactorAuthenticationConfirmed($user));
    event(new TwoFactorAuthenticationDisabled($user));

    expect(AuditLog::where('action', 'two_factor.enabled')->where('user_id', $user->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'two_factor.disabled')->where('user_id', $user->id)->count())->toBe(1);
});

describe('the admin gate', function () {
    beforeEach(fn () => Route::middleware(['web', 'auth', 'admin'])->get('/_test/admin', fn () => 'console'));

    it('sends guests to sign in', function () {
        $this->get('/_test/admin')->assertRedirect(route('login'));
    });

    it('pretends the area does not exist to ordinary users and to support staff', function (?string $role) {
        $this->actingAs(User::factory()->create(['platform_role' => $role]))->get('/_test/admin')->assertNotFound();
    })->with([['support'], [null]]);

    it('sends an admin without a confirmed second factor to set one up', function (string $role) {
        $this->actingAs(User::factory()->create(['platform_role' => $role]))
            ->get('/_test/admin')
            ->assertRedirect(route('security'));
    })->with(['admin', 'super_admin']);

    it('does not count an unconfirmed second factor', function () {
        $admin = User::factory()->create(['platform_role' => 'admin', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);

        $this->actingAs($admin)->get('/_test/admin')->assertRedirect(route('security'));
    });

    it('lets an admin with a confirmed second factor in', function () {
        $admin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get('/_test/admin')->assertOk()->assertSee('console');
    });

    it('turns a suspended admin away', function () {
        $admin = User::factory()->platformAdmin()->withTwoFactor()->create(['status' => 'suspended']);

        $this->actingAs($admin)->get('/_test/admin')->assertRedirect(route('account.suspended'));
    });
});
