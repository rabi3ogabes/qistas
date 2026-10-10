<?php

use App\Http\Controllers\Api\V1\TokenController;
use Laravel\Sanctum\PersonalAccessToken;

/*
 * Win Plan PP16: which phones are signed in to my account, when each was last seen and from where, and signing any
 * one of them out. Only my own sign-ins; API access tokens made for other software are a separate list.
 */

it('lists my signed-in phones with when and where each was last seen, marking this one', function () {
    [$user] = owner();
    $phone = $user->createToken('Qistas app (android)', ['app'])->plainTextToken;
    $user->createToken('Qistas app (iOS)', ['app']);
    $user->createToken('Accounting sync', [TokenController::ABILITY]);
    [$someoneElse] = owner();
    $someoneElse->createToken('Their phone', ['app']);

    $devices = $this->withToken($phone)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->getJson('/api/v1/devices')->assertOk()->json('data');

    expect(collect($devices)->pluck('name')->sort()->values()->all())->toBe(['Qistas app (android)', 'Qistas app (iOS)']);
    $this_one = collect($devices)->firstWhere('current', true);
    expect($this_one['name'])->toBe('Qistas app (android)')->and($this_one['last_used_ip'])->toBe('203.0.113.7')->and($this_one['last_used_at'])->not->toBeNull();
});

it('signs out the one phone chosen and leaves the others signed in', function () {
    [$user] = owner();
    $phone = $user->createToken('This phone', ['app'])->plainTextToken;
    $other = $user->createToken('Old phone', ['app'])->accessToken;

    $this->withToken($phone)->deleteJson("/api/v1/devices/{$other->id}")->assertNoContent();

    expect(PersonalAccessToken::query()->whereKey($other->id)->exists())->toBeFalse()
        ->and($user->tokens()->where('name', 'This phone')->exists())->toBeTrue();
});

it('never signs out someone else’s phone, nor an API token from here', function () {
    [$user] = owner();
    $phone = $user->createToken('This phone', ['app'])->plainTextToken;
    $integration = $user->createToken('Accounting sync', [TokenController::ABILITY])->accessToken;
    [$someoneElse] = owner();
    $theirs = $someoneElse->createToken('Their phone', ['app'])->accessToken;

    $this->withToken($phone)->deleteJson("/api/v1/devices/{$theirs->id}")->assertNotFound();
    $this->withToken($phone)->deleteJson("/api/v1/devices/{$integration->id}")->assertNotFound();

    expect(PersonalAccessToken::query()->count())->toBe(3);
});

it('lists the phones on the web security page, where one can be signed out', function () {
    [$user] = owner();
    $old = $user->createToken('Qistas app (android)', ['app'])->accessToken;

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get('/account/security')->assertOk()->assertSee('Phones signed in')->assertSee('Qistas app (android)');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('security.devices.destroy', $old->id))->assertRedirect(route('security'));

    expect(PersonalAccessToken::query()->whereKey($old->id)->exists())->toBeFalse();
});
