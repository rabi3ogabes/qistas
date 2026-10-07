<?php

use App\Actions\RegisterTenantOwner;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Hash;

function registrationData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Layla Haddad',
        'email' => 'Layla@Example.com',
        'password' => 'S3cure!Passw0rd',
        'business_name' => 'Al-Fares Electronics',
        'country' => 'SA',
        'locale' => 'ar',
    ], $overrides);
}

it('creates the user, the workspace, the owner membership and an audit row', function () {
    $user = app(RegisterTenantOwner::class)->handle(registrationData());
    $tenant = $user->tenants()->sole();

    expect($user->email)->toBe('layla@example.com')
        ->and($user->locale)->toBe('ar')
        ->and($user->platform_role)->toBeNull()
        ->and($user->status)->toBe('active')
        ->and($tenant->name)->toBe('Al-Fares Electronics')
        ->and($tenant->country)->toBe('SA')
        ->and($tenant->currency)->toBe('SAR')
        ->and($tenant->status)->toBe('active')
        ->and($tenant->pivot->role)->toBe('owner')
        ->and($tenant->owner_user_id)->toBe($user->id)
        ->and(Hash::check('S3cure!Passw0rd', $user->password))->toBeTrue()
        ->and($user->getRawOriginal('password'))->not->toBe('S3cure!Passw0rd')
        ->and(AuditLog::where('action', 'account.registered')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('gives every workspace a unique slug even for identical business names', function () {
    $a = app(RegisterTenantOwner::class)->handle(registrationData(['email' => 'a@example.com']))->tenants()->sole();
    $b = app(RegisterTenantOwner::class)->handle(registrationData(['email' => 'b@example.com']))->tenants()->sole();

    expect($a->slug)->not->toBe($b->slug)->and($a->slug)->toStartWith('al-fares-electronics');
});

it('falls back to the default currency for a country it does not know', function () {
    $tenant = app(RegisterTenantOwner::class)->handle(registrationData(['email' => 'z@example.com', 'country' => 'ZZ']))->tenants()->sole();

    expect($tenant->currency)->toBe(config('qistas.currency_default'));
});

it('rolls everything back when any step fails', function () {
    app(RegisterTenantOwner::class)->handle(registrationData());

    expect(fn () => app(RegisterTenantOwner::class)->handle(registrationData()))->toThrow(Exception::class);
    expect(User::count())->toBe(1);
});

it('does not let mass assignment grant platform privileges or change status', function () {
    expect(fn () => User::create([
        'name' => 'Mallory', 'email' => 'm@example.com', 'password' => 'S3cure!Passw0rd', 'platform_role' => 'super_admin',
    ]))->toThrow(MassAssignmentException::class);
});
