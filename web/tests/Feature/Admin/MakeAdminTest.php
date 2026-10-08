<?php

use App\Models\AuditLog;
use App\Models\User;

it('makes an existing account a platform administrator', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('qistas:make-admin', ['email' => 'Boss@Example.com'])
        ->expectsOutputToContain('is now super_admin')
        ->assertSuccessful();

    expect($user->fresh()->platform_role)->toBe('super_admin')->and($user->fresh()->isPlatformAdmin())->toBeTrue();
    expect(AuditLog::where('action', 'admin.role_granted')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('can grant the lighter admin role', function () {
    $user = User::factory()->create();

    $this->artisan('qistas:make-admin', ['email' => $user->email, '--role' => 'admin'])->assertSuccessful();

    expect($user->fresh()->platform_role)->toBe('admin');
});

it('refuses a role that does not exist', function () {
    $user = User::factory()->create();

    $this->artisan('qistas:make-admin', ['email' => $user->email, '--role' => 'owner'])->assertFailed();

    expect($user->fresh()->platform_role)->toBeNull();
});

it('does not invent accounts: the person must have registered', function () {
    $this->artisan('qistas:make-admin', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No account has that e-mail address')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('takes the rights away again', function () {
    $user = User::factory()->create(['platform_role' => 'super_admin', 'current_tenant_id' => (string) Str::uuid()]);

    $this->artisan('qistas:make-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();

    expect($user->fresh()->platform_role)->toBeNull()->and($user->fresh()->current_tenant_id)->toBeNull();
});

it('cannot be reached by registering with an address, or by any request at all', function () {
    $this->post('/register', [
        'name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
        'business_name' => 'Boss Trading', 'country' => 'SA', 'terms' => '1',
        'platform_role' => 'super_admin',
    ]);

    expect(User::where('email', 'boss@example.com')->value('platform_role'))->toBeNull();
});
