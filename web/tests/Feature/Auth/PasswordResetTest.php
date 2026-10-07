<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordQueued;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

const RESET_NEUTRAL = 'If an account exists for that email, we have sent a password reset link.';

it('shows the forgot-password page', function () {
    $this->get('/forgot-password')->assertOk()->assertSee('name="email"', false);
});

it('answers a reset request for an unknown email exactly as for a known one', function () {
    Notification::fake();
    $known = User::factory()->create(['email' => 'known@example.com']);

    $a = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'known@example.com']);
    $b = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);

    $a->assertRedirect('/forgot-password')->assertSessionHas('status', RESET_NEUTRAL)->assertSessionHasNoErrors();
    $b->assertRedirect('/forgot-password')->assertSessionHas('status', RESET_NEUTRAL)->assertSessionHasNoErrors();
    expect($b->getStatusCode())->toBe($a->getStatusCode());
    Notification::assertSentTimes(ResetPasswordQueued::class, 1);
    Notification::assertSentTo($known, ResetPasswordQueued::class);
});

it('gives API clients the same neutral answer for known and unknown emails', function () {
    Notification::fake();
    User::factory()->create(['email' => 'known@example.com']);

    $a = $this->postJson('/forgot-password', ['email' => 'known@example.com']);
    $b = $this->postJson('/forgot-password', ['email' => 'nobody@example.com']);

    expect($b->getStatusCode())->toBe($a->getStatusCode())->toBe(200)
        ->and($b->json())->toBe($a->json())
        ->and($a->json('message'))->toBe(RESET_NEUTRAL);
});

it('still validates the shape of the email', function () {
    $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
});

it('throttles reset requests from one address', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/forgot-password', ['email' => "u{$i}@example.com"])->assertSessionHasNoErrors();
    }

    $this->post('/forgot-password', ['email' => 'u6@example.com'])->assertStatus(429);
});

it('sets a new password from a valid link and refuses weak ones', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $payload = ['token' => $token, 'email' => $user->email];

    $this->post('/reset-password', $payload + ['password' => 'weak', 'password_confirmation' => 'weak'])
        ->assertSessionHasErrors('password');

    $this->post('/reset-password', $payload + ['password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
        ->assertRedirect(route('login'));

    expect(Hash::check('N3w!Passw0rd#1', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'password.reset')->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses an invalid reset token', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'not-a-real-token', 'email' => $user->email,
        'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('N3w!Passw0rd#1', $user->fresh()->password))->toBeFalse();
});
