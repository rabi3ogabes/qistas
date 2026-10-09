<?php

use App\Actions\RegisterTenantOwner;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/*
| A workspace has a time zone, so that anything scheduled for it ("remind at 9 in the morning") means the shop's morning.
| It is chosen from the country when the workspace is made, and an old workspace without one still has an answer.
*/

function registerIn(string $country): Tenant
{
    $user = app(RegisterTenantOwner::class)->handle([
        'name' => 'Layla Haddad', 'email' => 'layla'.uniqid().'@example.com', 'password' => TEST_PASSWORD,
        'business_name' => 'Al-Fares Electronics', 'country' => $country,
    ]);

    return $user->tenants()->sole();
}

it('is chosen from the country when a workspace is made', function (string $country, string $zone) {
    expect(registerIn($country)->timezone)->toBe($zone);
})->with([['SA', 'Asia/Riyadh'], ['AE', 'Asia/Dubai'], ['EG', 'Africa/Cairo'], ['FR', 'Europe/Paris'], ['PK', 'Asia/Karachi']]);

it('is UTC for a country the product does not know', function () {
    expect(registerIn('ZZ')->timezone)->toBe('UTC');
});

it('names a real zone for every country the product knows', function () {
    $countries = array_keys(config('qistas.countries'));

    expect($countries)->not->toBeEmpty()->and(array_keys(config('qistas.timezones')))->toEqualCanonicalizing($countries);

    foreach (config('qistas.timezones') as $country => $zone) {
        expect(in_array($zone, DateTimeZone::listIdentifiers(), true))->toBeTrue("{$country} has no real zone: {$zone}");
    }
});

describe('a workspace made before zones existed', function () {
    it('still answers, from its country', function () {
        $tenant = Tenant::factory()->create(['country' => 'SA']);
        Tenant::whereKey($tenant->id)->update(['timezone' => null]);

        expect($tenant->fresh()->localTimezone())->toBe('Asia/Riyadh');
    });

    it('answers UTC when its country is unknown too', function () {
        $tenant = Tenant::factory()->create(['country' => 'ZZ']);
        Tenant::whereKey($tenant->id)->update(['timezone' => null]);

        expect($tenant->fresh()->localTimezone())->toBe('UTC');
    });
});

it('uses the zone a workspace has chosen, and never one that is not real', function () {
    $tenant = Tenant::factory()->create(['country' => 'SA', 'timezone' => 'Asia/Dubai']);
    expect($tenant->localTimezone())->toBe('Asia/Dubai');

    Tenant::whereKey($tenant->id)->update(['timezone' => 'Mars/Olympus']);
    expect($tenant->fresh()->localTimezone())->toBe('Asia/Riyadh');
});

it('turns a moment into the shop’s own time', function () {
    $tenant = Tenant::factory()->create(['country' => 'SA', 'timezone' => 'Asia/Riyadh']);

    expect($tenant->localTime(CarbonImmutable::parse('2026-10-09 06:00:00', 'UTC'))->format('H:i'))->toBe('09:00');
});
