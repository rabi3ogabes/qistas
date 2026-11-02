<?php

use App\Actions\RegisterTenantOwner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| Signing up asks for five things: a name, an e-mail, a password, its confirmation and the terms. The business name and
| the place are found, not asked for; the workspace is named from the person until the owner confirms it. An older
| client that still sends a business name and a country is believed, and its workspace counts as confirmed. The web form
| (POST /register) and the phone app (POST /api/v1/auth/register) go through the same rules.
*/

/** @return array<string, mixed> the five things every client sends, plus [$more] */
function formSignUpFields(array $more = []): array
{
    return array_merge([
        'name' => 'Layla Haddad', 'email' => 'layla@example.com', 'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD, 'terms' => true,
    ], $more);
}

/**
 * Sign up through [$channel] ("web" or "api") with these fields and request headers. The test client sends an
 * Accept-Language of en-US unless told otherwise, so a request with no language says so.
 */
function formSignUp(string $channel, array $fields, array $headers = []): TestResponse
{
    $test = test()->withHeaders($headers + ['Accept-Language' => '']);

    return $channel === 'web'
        ? $test->post('/register', $fields)
        : $test->postJson('/api/v1/auth/register', $fields);
}

/** The one workspace the sign-up made. */
function formSignedUpTenant(): Tenant
{
    return User::where('email', 'layla@example.com')->sole()->tenants()->sole();
}

function formExpectRefused(TestResponse $response, string $channel, string $field): void
{
    if ($channel === 'web') {
        $response->assertSessionHasErrors($field);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors($field, 'error.fields');
    }

    expect(User::count())->toBe(0)->and(Tenant::count())->toBe(0);
}

beforeEach(fn () => config(['qistas.geo_header' => 'CF-IPCountry']));

describe('with nothing but the five fields', function () {
    it('opens the account and a workspace that is not yet confirmed', function (string $channel) {
        formSignUp($channel, formSignUpFields());

        $tenant = formSignedUpTenant();

        expect(User::count())->toBe(1)->and($tenant->isSetUp())->toBeFalse()->and($tenant->setup_confirmed_at)->toBeNull();
    })->with(['web', 'api']);

    it('puts the workspace where the CDN says', function (string $channel) {
        formSignUp($channel, formSignUpFields(), ['CF-IPCountry' => 'QA']);

        expect(formSignedUpTenant()->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'header']);
    })->with(['web', 'api']);

    it('puts the workspace where the browser’s language points', function (string $channel) {
        formSignUp($channel, formSignUpFields(), ['Accept-Language' => 'ar-SA']);

        expect(formSignedUpTenant()->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'country_source' => 'language']);
    })->with(['web', 'api']);

    it('puts the workspace where the browser’s time zone is, not where its language points', function (string $channel) {
        // A Saudi browser set to US English, no CDN header: the language says the United States, the device's clock says Riyadh.
        formSignUp($channel, formSignUpFields(['timezone' => 'Asia/Riyadh']), ['Accept-Language' => 'en-US,en;q=0.9']);

        $tenant = formSignedUpTenant();

        expect($tenant->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'country_source' => 'timezone'])
            ->and($tenant->isSetUp())->toBeFalse();
    })->with(['web', 'api']);

    it('puts the workspace where the CDN says, before the browser’s time zone', function (string $channel) {
        formSignUp($channel, formSignUpFields(['timezone' => 'Asia/Riyadh']), ['CF-IPCountry' => 'QA', 'Accept-Language' => 'en-US']);

        expect(formSignedUpTenant()->only(['country', 'timezone', 'country_source']))
            ->toBe(['country' => 'QA', 'timezone' => 'Asia/Riyadh', 'country_source' => 'header']);
    })->with(['web', 'api']);

    it('does not let a browser that says UTC put the workspace on UTC: the language and the country decide', function (string $channel) {
        formSignUp($channel, formSignUpFields(['timezone' => 'UTC']), ['Accept-Language' => 'ar-QA']);

        expect(formSignedUpTenant()->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'QA', 'currency' => 'QAR', 'timezone' => 'Asia/Qatar', 'country_source' => 'language']);
    })->with(['web', 'api']);

    it('does not let a browser that says UTC override the CDN’s country either', function (string $channel) {
        formSignUp($channel, formSignUpFields(['timezone' => 'UTC']), ['CF-IPCountry' => 'AE']);

        expect(formSignedUpTenant()->only(['country', 'timezone', 'country_source']))
            ->toBe(['country' => 'AE', 'timezone' => 'Asia/Dubai', 'country_source' => 'header']);
    })->with(['web', 'api']);

    it('leaves the phone app’s path as it was: an offset never names a country', function () {
        formSignUp('api', formSignUpFields(['utc_offset' => 180]), ['Accept-Language' => 'ar-SA']);

        expect(formSignedUpTenant()->only(['country', 'timezone', 'country_source']))
            ->toBe(['country' => 'SA', 'timezone' => 'Asia/Riyadh', 'country_source' => 'language']);
    });

    it('puts the workspace in the default country when nothing says', function (string $channel) {
        formSignUp($channel, formSignUpFields());

        expect(formSignedUpTenant()->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'SA', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'country_source' => 'default']);
    })->with(['web', 'api']);

    it('takes the default from the setting', function (string $channel) {
        config(['qistas.default_country' => 'AE']);

        formSignUp($channel, formSignUpFields());

        expect(formSignedUpTenant()->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'country_source' => 'default']);
    })->with(['web', 'api']);

    it('keeps a detected country the product has no currency for, on the default currency', function (string $channel) {
        formSignUp($channel, formSignUpFields(), ['CF-IPCountry' => 'PS']);

        expect(formSignedUpTenant()->only(['country', 'currency', 'timezone']))
            ->toBe(['country' => 'PS', 'currency' => config('qistas.currency_default'), 'timezone' => 'UTC']);
    })->with(['web', 'api']);
});

describe('the time zone', function () {
    it('is the real zone the client sends', function (string $channel) {
        formSignUp($channel, formSignUpFields(['timezone' => 'Asia/Riyadh']), ['CF-IPCountry' => 'AE']);

        expect(formSignedUpTenant()->only(['country', 'timezone']))->toBe(['country' => 'AE', 'timezone' => 'Asia/Riyadh']);
    })->with(['web', 'api']);

    it('is the country’s usual zone when the client sends one that does not exist', function (string $channel) {
        formSignUp($channel, formSignUpFields(['timezone' => 'Mars/Olympus']), ['CF-IPCountry' => 'AE']);

        expect(formSignedUpTenant()->timezone)->toBe('Asia/Dubai');
    })->with(['web', 'api']);

    it('is read from the phone’s offset in minutes against the country’s zones', function (int $offset, string $country, string $zone) {
        formSignUp('api', formSignUpFields(['utc_offset' => $offset]), ['CF-IPCountry' => $country]);

        expect(formSignedUpTenant()->timezone)->toBe($zone);
    })->with([
        'Riyadh' => [180, 'SA', 'Asia/Riyadh'],
        'Dubai' => [240, 'AE', 'Asia/Dubai'],
        'western Australia, which the country table does not name' => [480, 'AU', 'Australia/Perth'],
    ]);

    it('prefers the zone name to the offset', function () {
        formSignUp('api', formSignUpFields(['timezone' => 'Asia/Qatar', 'utc_offset' => 240]), ['CF-IPCountry' => 'AE']);

        expect(formSignedUpTenant()->timezone)->toBe('Asia/Qatar');
    });
});

describe('the name of the workspace', function () {
    it('is "Business of" and the person’s first name, in the language they signed up in', function (string $channel, string $locale, string $name, string $expected) {
        formSignUp($channel, formSignUpFields(['name' => $name, 'locale' => $locale]));

        $tenant = formSignedUpTenant();

        expect($tenant->name)->toBe($expected)->and($tenant->isSetUp())->toBeFalse();
    })->with(function () {
        foreach (['web', 'api'] as $channel) {
            yield "{$channel} in English" => [$channel, 'en', 'Layla Haddad', 'Business of Layla'];
            yield "{$channel} in Arabic" => [$channel, 'ar', 'ليلى حداد', 'نشاط ليلى'];
        }
    });

    it('is told in the language posted, not the one the request happens to be in', function () {
        formSignUp('api', formSignUpFields(['name' => 'ليلى حداد', 'locale' => 'ar']), ['Accept-Language' => 'en-US']);

        expect(formSignedUpTenant()->name)->toBe('نشاط ليلى');
    });

    it('gives two people with the same first name their own address', function (string $channel) {
        app(RegisterTenantOwner::class)->handle(['name' => 'Layla Karam', 'email' => 'layla.one@example.com', 'password' => TEST_PASSWORD]);

        formSignUp($channel, formSignUpFields());

        $slugs = Tenant::pluck('slug');

        expect($slugs)->toHaveCount(2)->and($slugs->unique())->toHaveCount(2)
            ->and($slugs->every(fn (string $slug) => str_starts_with($slug, 'business-of-layla')))->toBeTrue();
    })->with(['web', 'api']);

    it('stays within what a name column holds, even for a 255-character name', function (string $channel, string $name) {
        formSignUp($channel, formSignUpFields(['name' => $name]));

        $tenant = formSignedUpTenant();

        expect(mb_strlen($name))->toBe(255)
            ->and(mb_strlen($tenant->name))->toBeLessThanOrEqual(255)
            ->and($tenant->name)->toBe('Business of '.mb_substr($name, 0, 40));
    })->with(function () {
        foreach (['web', 'api'] as $channel) {
            yield "{$channel} one long word" => [$channel, str_repeat('a', 255)];
        }
    });

    it('uses the first word only, however many the name has', function () {
        formSignUp('api', formSignUpFields(['name' => '  Layla   Mona  Haddad ']));

        expect(formSignedUpTenant()->name)->toBe('Business of Layla');
    });
});

describe('a client that still sends a business name and a country', function () {
    it('is believed, and its workspace is confirmed', function (string $channel) {
        formSignUp($channel, formSignUpFields(['business_name' => 'Al-Fares Electronics', 'country' => 'ae']), ['CF-IPCountry' => 'QA']);

        $tenant = formSignedUpTenant();

        expect($tenant->name)->toBe('Al-Fares Electronics')
            ->and($tenant->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'country_source' => 'chosen'])
            ->and($tenant->isSetUp())->toBeTrue();
    })->with(['web', 'api']);

    it('is believed about the name alone, and the place is still found', function (string $channel) {
        formSignUp($channel, formSignUpFields(['business_name' => 'Al-Fares Electronics']), ['CF-IPCountry' => 'QA']);

        $tenant = formSignedUpTenant();

        expect($tenant->name)->toBe('Al-Fares Electronics')
            ->and($tenant->only(['country', 'country_source']))->toBe(['country' => 'QA', 'country_source' => 'header'])
            ->and($tenant->isSetUp())->toBeTrue();
    })->with(['web', 'api']);

    it('is believed about the country alone, and the name is still made', function (string $channel) {
        formSignUp($channel, formSignUpFields(['country' => 'EG']), ['CF-IPCountry' => 'QA']);

        $tenant = formSignedUpTenant();

        expect($tenant->name)->toBe('Business of Layla')
            ->and($tenant->only(['country', 'currency', 'timezone', 'country_source']))
            ->toBe(['country' => 'EG', 'currency' => 'EGP', 'timezone' => 'Africa/Cairo', 'country_source' => 'chosen'])
            ->and($tenant->isSetUp())->toBeFalse();
    })->with(['web', 'api']);

    it('keeps its own 255-character business name', function () {
        formSignUp('api', formSignUpFields(['business_name' => str_repeat('b', 255)]));

        expect(formSignedUpTenant()->name)->toBe(str_repeat('b', 255));
    });
});

describe('what is refused', function () {
    it('is a country that is not a two-letter code', function (string $channel, string $country) {
        formExpectRefused(formSignUp($channel, formSignUpFields(['country' => $country])), $channel, 'country');
    })->with(function () {
        foreach (['web', 'api'] as $channel) {
            foreach (['ZZZ', '12', 'S', 'Saudi'] as $country) {
                yield "{$channel} {$country}" => [$channel, $country];
            }
        }
    });

    it('is an offset no time zone has, or one that is not a number', function (string $channel, mixed $offset) {
        formExpectRefused(formSignUp($channel, formSignUpFields(['utc_offset' => $offset])), $channel, 'utc_offset');
    })->with(function () {
        foreach (['web', 'api'] as $channel) {
            foreach ([9999, -841, 841, 'three hours', '1.5'] as $offset) {
                yield "{$channel} {$offset}" => [$channel, $offset];
            }
        }
    });

    it('is a zone name or a business name that is too long', function (string $channel, string $field, string $value) {
        formExpectRefused(formSignUp($channel, formSignUpFields([$field => $value])), $channel, $field);
    })->with(function () {
        foreach (['web', 'api'] as $channel) {
            yield "{$channel} zone" => [$channel, 'timezone', str_repeat('a', 65)];
            yield "{$channel} business" => [$channel, 'business_name', str_repeat('b', 256)];
        }
    });

    it('still needs the five things', function (string $channel, string $field) {
        formExpectRefused(formSignUp($channel, formSignUpFields([$field => ''])), $channel, $field);
    })->with(function () {
        foreach (['web', 'api'] as $channel) {
            foreach (['name', 'email', 'password', 'terms'] as $field) {
                yield "{$channel} {$field}" => [$channel, $field];
            }
        }
    });

    it('accepts the offset at both ends of what a clock can be', function (int $offset) {
        formSignUp('api', formSignUpFields(['utc_offset' => $offset]), ['CF-IPCountry' => 'SA'])->assertCreated();
    })->with([-840, 0, 840]);
});
