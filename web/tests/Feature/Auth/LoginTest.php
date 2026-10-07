<?php

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

it('shows the sign-in page', function () {
    $this->get('/login')->assertOk()->assertSee('name="email"', false);
});

it('signs a user in and starts a fresh session', function () {
    [$user] = makeAccount();
    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($before);
});

it('accepts the email in any letter case', function () {
    [$user] = makeAccount(['email' => 'owner@example.com']);

    $this->post('/login', ['email' => 'OWNER@Example.COM', 'password' => TEST_PASSWORD]);

    $this->assertAuthenticatedAs($user);
});

it('gives the same answer for an unknown email and a wrong password', function () {
    [$user] = makeAccount();

    $wrong = $this->post('/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1']);
    $unknown = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'Wrong!Passw0rd1']);

    $wrong->assertSessionHasErrors(['email' => __('auth.failed')]);
    $unknown->assertSessionHasErrors(['email' => __('auth.failed')]);
    expect($unknown->getStatusCode())->toBe($wrong->getStatusCode());
    $this->assertGuest();
});

it('locks an account out after five failed attempts in a minute, even for the right password', function () {
    [$user] = makeAccount();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])->assertStatus(429);
    $this->assertGuest();
});

it('throttles one address that tries many different accounts', function () {
    foreach (range(1, 20) as $i) {
        $this->post('/login', ['email' => "guess{$i}@example.com", 'password' => 'Wrong!Passw0rd1'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'guess21@example.com', 'password' => 'Wrong!Passw0rd1'])->assertStatus(429);
});

it('blocks a suspended user with a clear message, but only after the password is right', function () {
    [$user] = makeAccount(['status' => 'suspended']);

    $this->post('/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])
        ->assertSessionHasErrors(['email' => __('Your account has been suspended. Please contact support.')]);
    $this->assertGuest();
});

it('blocks members of a suspended workspace', function () {
    [$user] = makeAccount(tenant: ['status' => 'suspended']);

    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD])
        ->assertSessionHasErrors(['email' => __('Your account has been suspended. Please contact support.')]);
    $this->assertGuest();
});

it('lets in a member of several workspaces while at least one is active', function () {
    [$user] = makeAccount(tenant: ['status' => 'suspended']);
    Tenant::factory()->create()->users()->attach($user->id, ['role' => 'staff']);

    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);

    $this->assertAuthenticatedAs($user);
});

it('signs out someone who is suspended while already signed in', function () {
    Route::middleware(['web', 'auth', 'account.active'])->get('/_test/protected', fn () => 'secret');
    [$user] = makeAccount();
    $this->actingAs($user)->get('/_test/protected')->assertOk();

    $user->forceFill(['status' => 'suspended'])->save();

    $this->get('/_test/protected')->assertRedirect(route('account.suspended'));
    $this->assertGuest();
});

it('explains a suspension on a public page', function () {
    $this->get(route('account.suspended'))->assertOk()->assertSee(__('Your account has been suspended. Please contact support.'));
});

it('records sign-ins, failures and blocked sign-ins in the audit log, never the password', function () {
    [$user] = makeAccount();
    [$blocked] = makeAccount(['status' => 'suspended']);

    $this->post('/login', ['email' => $user->email, 'password' => 'Wrong!Passw0rd1']);
    $this->post('/login', ['email' => $user->email, 'password' => TEST_PASSWORD]);
    auth()->logout();
    $this->post('/login', ['email' => $blocked->email, 'password' => TEST_PASSWORD]);

    expect(AuditLog::where('action', 'login.failed')->where('user_id', $user->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'login.succeeded')->where('user_id', $user->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'login.blocked')->where('user_id', $blocked->id)->count())->toBe(1)
        ->and(AuditLog::all()->toJson())->not->toContain('Wrong!Passw0rd1')->not->toContain(TEST_PASSWORD);
});

it('signs out and ends the session', function () {
    [$user] = makeAccount();

    $this->actingAs($user)->post('/logout')->assertRedirect();

    $this->assertGuest();
});

it('keeps signed-in people out of the sign-in page', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(config('fortify.home'));
});
