<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use Illuminate\Support\Facades\Notification;

function signUpPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Layla Haddad',
        'email' => 'layla@example.com',
        'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD,
        'business_name' => 'Al-Fares Electronics',
        'country' => 'SA',
        'locale' => 'ar',
        'terms' => '1',
    ], $overrides);
}

it('shows the sign-up page', function () {
    $this->get('/register')->assertOk()->assertSee('name="business_name"', false);
});

it('creates the account, signs the new owner in and sends the verification email', function () {
    Notification::fake();

    $this->post('/register', signUpPayload())->assertRedirect(config('fortify.home'));

    $user = User::where('email', 'layla@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->tenants()->sole()->name)->toBe('Al-Fares Electronics')
        ->and($user->locale)->toBe('ar')
        ->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmailQueued::class);
});

it('rejects weak passwords', function (string $password) {
    $this->post('/register', signUpPayload(['password' => $password, 'password_confirmation' => $password]))
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
})->with([
    'too short' => 'Ab1!xyz',
    'no symbol' => 'Abcdefghij12',
    'no digit' => 'Abcdefghij!!',
    'no upper case' => 'abcdefghij1!',
    'no lower case' => 'ABCDEFGHIJ1!',
]);

it('rejects invalid sign-up data and creates nothing', function (array $overrides, string $field) {
    $this->post('/register', signUpPayload($overrides))->assertSessionHasErrors($field);

    expect(User::count())->toBe(0)->and(Tenant::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'missing business name' => [['business_name' => ''], 'business_name'],
    'country is not a two-letter code' => [['country' => 'Saudi'], 'country'],
    'unsupported language' => [['locale' => 'xx'], 'locale'],
    'terms not accepted' => [['terms' => null], 'terms'],
    'password confirmation differs' => [['password_confirmation' => 'something else'], 'password'],
]);

it('refuses an email that is already registered, whatever its letter case', function () {
    User::factory()->create(['email' => 'layla@example.com']);

    $this->post('/register', signUpPayload(['email' => 'LAYLA@Example.com']))->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
});

it('does not let a client choose its own role, status or workspace', function () {
    $this->post('/register', signUpPayload([
        'platform_role' => 'super_admin', 'status' => 'active', 'tenant_id' => 'x', 'role' => 'owner',
    ]));

    expect(User::where('email', 'layla@example.com')->sole()->platform_role)->toBeNull();
});

it('throttles sign-up attempts from one address', function () {
    foreach (range(1, 5) as $_) {
        $this->post('/register', signUpPayload(['name' => '']))->assertSessionHasErrors('name');
    }

    $this->post('/register', signUpPayload(['name' => '']))->assertStatus(429);
});

it('writes an audit entry for the new account', function () {
    $this->post('/register', signUpPayload());

    expect(AuditLog::where('action', 'account.registered')->count())->toBe(1);
});
