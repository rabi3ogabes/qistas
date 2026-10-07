<?php

use App\Models\User;

it('renders the choose-a-new-password page with the token and email from the link', function () {
    $this->get('/reset-password/abc123?email=layla@example.com')
        ->assertOk()
        ->assertSee('name="token" value="abc123"', false)
        ->assertSee('value="layla@example.com"', false)
        ->assertSee('name="password_confirmation"', false);
});

it('renders the two-factor challenge with a recovery-code alternative', function () {
    [$user] = makeAccount(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);
    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertSee('name="code"', false)
        ->assertSee('autocomplete="one-time-code"', false)
        ->assertSee('name="recovery_code"', false);
});

it('renders the confirm-password page', function () {
    $this->actingAs(User::factory()->create())->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('name="password"', false)
        ->assertSee('autocomplete="current-password"', false);
});

it('marks every auth page as not for search engines and sets the document language and direction', function () {
    $this->get('/login')->assertSee('<meta name="robots" content="noindex">', false)->assertSee('lang="en" dir="ltr"', false);

    app()->setLocale('ar');
    $this->get('/login')->assertSee('lang="ar" dir="rtl"', false);
});

it('lists the supported countries in the sign-up form', function () {
    $this->get('/register')->assertSee('<option value="SA"', false)->assertSee('Saudi Arabia');
});

it('keeps what was typed after a validation error, except passwords', function () {
    $this->from('/register')->post('/register', [
        'name' => 'Layla', 'email' => 'layla@example.com', 'password' => 'weak', 'password_confirmation' => 'weak',
        'business_name' => 'Al-Fares', 'country' => 'SA', 'terms' => '1',
    ]);

    $this->get('/register')
        ->assertSee('value="Layla"', false)
        ->assertSee('value="Al-Fares"', false)
        ->assertDontSee('value="weak"', false);
});
