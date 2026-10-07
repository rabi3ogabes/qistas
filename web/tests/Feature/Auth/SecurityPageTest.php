<?php

use App\Models\User;

/** Signed in, with the password confirmed a moment ago (the security area's "sudo mode"). */
function confirmedSession(User $user)
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
}

it('sends guests to sign in', function () {
    $this->get(route('security'))->assertRedirect(route('login'));
});

it('asks for the password again before showing security settings', function () {
    $this->actingAs(User::factory()->create())->get(route('security'))->assertRedirect(route('password.confirm'));
});

it('offers to turn on two-factor authentication', function () {
    confirmedSession(User::factory()->create())
        ->get(route('security'))
        ->assertOk()
        ->assertSee('action="'.url('/user/two-factor-authentication').'"', false);
});

it('shows the QR code and asks for a code while setup is unconfirmed', function () {
    $user = User::factory()->create();
    confirmedSession($user)->post('/user/two-factor-authentication');

    confirmedSession($user->fresh())->get(route('security'))
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('action="'.url('/user/confirmed-two-factor-authentication').'"', false);
});

it('explains that two-factor authentication is required for administrators', function () {
    confirmedSession(User::factory()->platformAdmin()->create())
        ->get(route('security'))
        ->assertSee(__('Administrators must use two-factor authentication.'));
});

it('offers recovery codes and a way to turn it off once confirmed, without printing the codes', function () {
    confirmedSession(User::factory()->withTwoFactor()->create())->get(route('security'))
        ->assertOk()
        ->assertDontSee('recovery-code-1')
        ->assertSee(route('security.recovery-codes'), false)
        ->assertSee('action="'.url('/user/two-factor-authentication').'"', false);
});

it('shows recovery codes on their own page', function () {
    confirmedSession(User::factory()->withTwoFactor()->create())->get(route('security.recovery-codes'))
        ->assertOk()
        ->assertSee('recovery-code-1')
        ->assertSee('recovery-code-2');
});

it('has no recovery codes to show before two-factor authentication is confirmed', function () {
    confirmedSession(User::factory()->create())->get(route('security.recovery-codes'))->assertNotFound();
});

it('turns Fortify status keys into readable messages', function () {
    confirmedSession(User::factory()->withTwoFactor()->create())
        ->withSession(['status' => 'two-factor-authentication-confirmed'])
        ->get(route('security'))
        ->assertSee(__('Two-factor authentication is on.'))
        ->assertDontSee('two-factor-authentication-confirmed');
});

it('does not expose passkey endpoints until they are designed and shipped', function () {
    $this->getJson('/passkeys/login/options')->assertNotFound();
});

it('serves the email verification notice to an unverified user', function () {
    $this->actingAs(User::factory()->unverified()->create())->get('/email/verify')->assertOk();
});
