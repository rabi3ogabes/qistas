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

/** What a sign-up without a business name or a country hands the action. */
function registrationBare(array $overrides = []): array
{
    return array_merge(['name' => 'Layla Haddad', 'email' => 'layla@example.com', 'password' => 'S3cure!Passw0rd'], $overrides);
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

it('starts every new workspace on the Free plan', function () {
    $tenant = app(RegisterTenantOwner::class)->handle(registrationData())->tenants()->sole();

    expect($tenant->subscription->plan->key)->toBe('free')
        ->and($tenant->subscription->status)->toBe('active')
        ->and($tenant->subscription->current_period_end)->toBeNull();
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

describe('a sign-up with no business name and no country', function () {
    it('names the workspace after the person and leaves it unconfirmed, in the default country', function () {
        $tenant = app(RegisterTenantOwner::class)->handle(registrationBare())->tenants()->sole();

        expect($tenant->name)->toBe('Business of Layla')
            ->and($tenant->slug)->toBe('business-of-layla')
            ->and($tenant->setup_confirmed_at)->toBeNull()
            ->and($tenant->isSetUp())->toBeFalse()
            ->and($tenant->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'country_source' => 'default']);
    });

    it('translates the placeholder into the locale it is given, and into English when it is given none', function () {
        $arabic = app(RegisterTenantOwner::class)->handle(registrationBare(['name' => 'ليلى حداد', 'locale' => 'ar']))->tenants()->sole();
        $none = app(RegisterTenantOwner::class)->handle(registrationBare(['email' => 'other@example.com']))->tenants()->sole();

        expect($arabic->name)->toBe('نشاط ليلى')->and($none->name)->toBe('Business of Layla');
    });

    it('takes the first word of the name, at most forty characters of it', function (string $name, string $first) {
        $tenant = app(RegisterTenantOwner::class)->handle(registrationBare(['name' => $name]))->tenants()->sole();

        expect($tenant->name)->toBe("Business of {$first}");
    })->with([
        'two words' => ['Layla Haddad', 'Layla'],
        'extra spaces' => ["  Layla \t Haddad ", 'Layla'],
        'one word' => ['Layla', 'Layla'],
        'a very long word' => [str_repeat('x', 255), str_repeat('x', 40)],
        'a long Arabic word' => [str_repeat('ل', 100), str_repeat('ل', 40)],
    ]);

    it('gives each workspace its own address when two people share a first name', function () {
        $a = app(RegisterTenantOwner::class)->handle(registrationBare())->tenants()->sole();
        $b = app(RegisterTenantOwner::class)->handle(registrationBare(['email' => 'b@example.com', 'name' => 'Layla Karam']))->tenants()->sole();

        expect($a->slug)->toBe('business-of-layla')->and($b->slug)->toStartWith('business-of-layla-')->and($b->slug)->not->toBe($a->slug);
    });

    it('gives people whose names have no Latin letters an address of their own too', function () {
        $a = app(RegisterTenantOwner::class)->handle(registrationBare(['name' => 'ليلى حداد', 'locale' => 'ar']))->tenants()->sole();
        $b = app(RegisterTenantOwner::class)->handle(registrationBare(['name' => 'ليلى كرم', 'email' => 'b@example.com', 'locale' => 'ar']))->tenants()->sole();

        expect($a->slug)->not->toBe('')->and($b->slug)->not->toBe('')->and($b->slug)->not->toBe($a->slug);
    });

    it('treats a blank business name as none', function (?string $blank) {
        $tenant = app(RegisterTenantOwner::class)->handle(registrationBare(['business_name' => $blank]))->tenants()->sole();

        expect($tenant->name)->toBe('Business of Layla')->and($tenant->setup_confirmed_at)->toBeNull();
    })->with([[null], [''], ['   ']]);

    it('never lets the caller’s data confirm the workspace, only a business name does', function () {
        $tenant = app(RegisterTenantOwner::class)->handle(registrationBare(['setup_confirmed_at' => now()->toDateTimeString()]))->tenants()->sole();

        expect($tenant->setup_confirmed_at)->toBeNull();
    });
});

describe('a sign-up with a business name', function () {
    it('confirms the workspace at once', function () {
        $this->freezeSecond();

        $tenant = app(RegisterTenantOwner::class)->handle(registrationData())->tenants()->sole();

        expect($tenant->setup_confirmed_at?->equalTo(now()))->toBeTrue()->and($tenant->isSetUp())->toBeTrue();
    });

    it('remembers a country it was given as chosen, unless the caller says how it was found', function () {
        $chosen = app(RegisterTenantOwner::class)->handle(registrationData())->tenants()->sole();
        $found = app(RegisterTenantOwner::class)->handle(registrationData(['email' => 'f@example.com', 'country_source' => 'header']))->tenants()->sole();

        expect($chosen->country_source)->toBe('chosen')->and($found->country_source)->toBe('header');
    });

    it('is still a default country when none is given', function () {
        $data = registrationData();
        unset($data['country']);

        $tenant = app(RegisterTenantOwner::class)->handle($data)->tenants()->sole();

        expect($tenant->only(['name', 'country', 'country_source']))->toBe(['name' => 'Al-Fares Electronics', 'country' => 'SA', 'country_source' => 'default']);
    });
});

it('rolls everything back when any step fails', function () {
    app(RegisterTenantOwner::class)->handle(registrationData());

    expect(fn () => app(RegisterTenantOwner::class)->handle(registrationData()))->toThrow(Exception::class);
    expect(User::count())->toBe(1);
})->group('db-errors'); // a duplicate e-mail makes the database raise an error: left out on the PGlite stand-in

it('does not let mass assignment grant platform privileges or change status', function () {
    expect(fn () => User::create([
        'name' => 'Mallory', 'email' => 'm@example.com', 'password' => 'S3cure!Passw0rd', 'platform_role' => 'super_admin',
    ]))->toThrow(MassAssignmentException::class);
});
