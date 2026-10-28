<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

/*
| The owner's decision of 28 Oct 2026: the admin team never meets two-factor. No code at sign-in (web or phone), no
| setup page, no team rule. Business owners keep it, optional, exactly as before.
*/

/** The columns of someone who once switched two-factor on. */
function storedTwoFactor(): array
{
    return [
        'two_factor_confirmed_at' => now(),
        'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['abcde-12345'])),
    ];
}

/** A signed-in session that has just confirmed its password, which the security page asks for. */
function passwordConfirmedAs(User $user)
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

describe('signing in', function () {
    it('lets an admin in with the password alone, even if two-factor was once switched on', function (string $role) {
        [$admin] = makeAccount(['platform_role' => $role] + storedTwoFactor());

        $response = $this->post('/login', ['email' => $admin->email, 'password' => TEST_PASSWORD]);

        expect($response->headers->get('Location'))->not->toBe(route('two-factor.login'));
        $this->assertAuthenticatedAs($admin);
    })->with(['admin', 'super_admin']);

    it('still asks a business owner for the code', function () {
        [$owner] = makeAccount(storedTwoFactor());

        $this->post('/login', ['email' => $owner->email, 'password' => TEST_PASSWORD])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    });

    it('does not ask an admin signing in on the phone for a code', function () {
        [$admin] = makeAccount(['platform_role' => 'admin'] + storedTwoFactor());

        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => TEST_PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    });

    it('still asks a business owner on the phone for the code', function () {
        [$owner] = makeAccount(storedTwoFactor());

        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TEST_PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'two_factor_required');
    });
});

describe('the account the app reads', function () {
    it('says two-factor is not available to the admin team, and is to everyone else', function () {
        [$admin] = makeAccount(['platform_role' => 'admin'] + storedTwoFactor());
        Sanctum::actingAs($admin, ['app']);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_available', false)
            ->assertJsonPath('data.user.two_factor', false);

        [$owner] = makeAccount(storedTwoFactor());
        Sanctum::actingAs($owner, ['app']);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.two_factor_available', true)
            ->assertJsonPath('data.user.two_factor', true);
    });
});

describe('the security page', function () {
    // Every branch the page has for two-factor: never set up, a key waiting to be confirmed, and confirmed.
    it('shows an admin no two-factor controls at all, only what else lives there', function (string $state) {
        $admin = match ($state) {
            'never set up' => User::factory()->platformAdmin()->create(),
            'a key waiting to be confirmed' => User::factory()->platformAdmin()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]),
            'confirmed' => User::factory()->platformAdmin()->withTwoFactor()->create(),
        };

        passwordConfirmedAs($admin)->get(route('security'))
            ->assertOk()
            ->assertSee(__('Phones signed in'))
            ->assertSee(__('The phones signed in with your account.'))
            ->assertDontSee(__('A second step at sign-in keeps your business data safe even if your password leaks.'))
            ->assertDontSee(__('Turn on two-factor authentication'))
            ->assertDontSee(__('Turn off two-factor authentication'))
            ->assertDontSee(__('Two-factor authentication is on.'))
            ->assertDontSee(__('Confirm and turn on'))
            ->assertDontSee(__('Get a new key'))
            ->assertDontSee(__('Trusted devices'))
            ->assertDontSee(route('security.recovery-codes'), false)
            ->assertDontSee('two-factor-authentication', false);
    })->with(['never set up', 'a key waiting to be confirmed', 'confirmed']);

    it('still offers a business owner two-factor', function () {
        [$owner] = owner();

        passwordConfirmedAs($owner)->get(route('security'))->assertOk()->assertSee(__('Turn on two-factor authentication'));
    });
});

describe('setting it up', function () {
    it('is refused for an admin whatever route is tried', function () {
        $admin = User::factory()->platformAdmin()->create();
        $session = passwordConfirmedAs($admin);

        $session->post('/user/two-factor-authentication')->assertNotFound();
        $session->post('/user/confirmed-two-factor-authentication', ['code' => '123456'])->assertNotFound();
        $session->post(route('security.two-factor.restart'))->assertNotFound();
        $session->get(route('security.recovery-codes'))->assertNotFound();
        $session->post(route('security.trusted-devices.forget'))->assertNotFound();

        expect($admin->fresh()->two_factor_secret)->toBeNull();
    });

    it('is still open to a business owner', function () {
        [$owner] = owner();

        passwordConfirmedAs($owner)->post('/user/two-factor-authentication')->assertRedirect();

        expect($owner->fresh()->two_factor_secret)->not->toBeNull();
    });
});

describe('the admin area', function () {
    it('has no team security page and no menu entry for one', function () {
        $admin = User::factory()->platformAdmin()->create();

        expect(Route::has('admin.security.index'))->toBeFalse();
        $this->actingAs($admin)->get('/admin/security')->assertNotFound();
        $this->actingAs($admin)->get(route('admin.home'))->assertOk()->assertDontSee(__('Team security'));
    });

    it('opens for an admin who has not set two-factor up, and for one who has', function () {
        $this->actingAs(User::factory()->platformAdmin()->create())->get(route('admin.home'))->assertOk();
        $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())->get(route('admin.home'))->assertOk();
    });
});
