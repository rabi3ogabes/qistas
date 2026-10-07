<?php

use App\Models\User;
use App\Notifications\VerifyEmailQueued;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('updates name and language', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)->put('/user/profile-information', ['name' => 'New Name', 'email' => $user->email, 'locale' => 'ar'])
        ->assertSessionHasNoErrors();

    expect($user->fresh())->name->toBe('New Name')->locale->toBe('ar')->email_verified_at->not->toBeNull();
});

it('asks for a new email to be verified and sends the link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => 'New@Example.com'])
        ->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBe('new@example.com')->email_verified_at->toBeNull();
    Notification::assertSentTo($user->fresh(), VerifyEmailQueued::class);
});

it('refuses an email that belongs to someone else', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => 'TAKEN@example.com'])
        ->assertSessionHasErrorsIn('updateProfileInformation', 'email');
});

it('changes the password only with the current one and a strong replacement', function () {
    $user = User::factory()->create(['password' => TEST_PASSWORD]);
    $change = fn (array $input) => $this->actingAs($user)->put('/user/password', $input);

    $change(['current_password' => 'wrong', 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
        ->assertSessionHasErrorsIn('updatePassword', 'current_password');
    $change(['current_password' => TEST_PASSWORD, 'password' => 'weak', 'password_confirmation' => 'weak'])
        ->assertSessionHasErrorsIn('updatePassword', 'password');
    expect(Hash::check(TEST_PASSWORD, $user->fresh()->password))->toBeTrue();

    $change(['current_password' => TEST_PASSWORD, 'password' => 'N3w!Passw0rd#1', 'password_confirmation' => 'N3w!Passw0rd#1'])
        ->assertSessionHasNoErrors();
    expect(Hash::check('N3w!Passw0rd#1', $user->fresh()->password))->toBeTrue();
});
