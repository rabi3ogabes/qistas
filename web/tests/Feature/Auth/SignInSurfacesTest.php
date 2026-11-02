<?php

use App\Auth\SignInPresenter;
use App\Models\SignInEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| Where the sign-in record is shown (go-live basics, Task 7): to the person on the API and on the security page, and
| to platform staff, per member, on the business page. The record is the person's own, so every test here also pins
| who must NOT see a row.
*/

/** @var list<string> the keys of one sign-in as the API sends it, in order */
const SIGN_IN_SURFACES_KEYS = ['id', 'method', 'channel', 'outcome', 'device_type', 'os', 'browser', 'app_version', 'device_name', 'ip', 'ip_country', 'proxy', 'proxy_signals', 'created_at'];

/**
 * One row of someone's sign-in history; every column can be overridden.
 *
 * @param  array<string, mixed>  $with
 */
function signInSurfacesRow(User $user, array $with = []): SignInEvent
{
    return SignInEvent::query()->forceCreate($with + [
        'user_id' => $user->id, 'method' => 'password', 'channel' => 'web', 'outcome' => 'success',
        'device_type' => 'desktop', 'os' => 'Windows', 'browser' => 'Chrome', 'app_version' => null, 'device_name' => null,
        'ip' => '203.0.113.9', 'ip_country' => 'QA', 'proxy' => 'not_detected', 'proxy_signals' => [],
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0', 'created_at' => now(),
    ]);
}

/**
 * [$count] rows for [$user], one a minute, the newest first made last: row 1 is the newest. The browser is named
 * "{$prefix}01" for the newest, "{$prefix}02" for the one before, and so on, so a page can be told which rows it shows.
 *
 * @return list<SignInEvent> newest first
 */
function signInSurfacesHistory(User $user, int $count, string $prefix = 'Browser'): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = signInSurfacesRow($user, ['browser' => sprintf('%s%02d', $prefix, $i), 'created_at' => now()->subMinutes($i)]);
    }

    return $rows;
}

/** The property names the `SignInEvent` schema of docs/api/openapi.yaml lists, in order. @return list<string> */
function signInSurfacesDocumentedProperties(): array
{
    $names = [];
    $inside = $properties = false;

    foreach (file(base_path('../docs/api/openapi.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '    SignInEvent:') {
            $inside = true;
        } elseif ($inside && preg_match('/^    \S/', $line)) {
            break;
        } elseif ($inside && $line === '      properties:') {
            $properties = true;
        } elseif ($inside && $properties && preg_match('/^        ([a-z_]+):/', $line, $match)) {
            $names[] = $match[1];
        }
    }

    return $names;
}

/** The security page, opened the way the person gets there: signed in and the password confirmed a moment ago. */
function signInSurfacesSecurityPage(User $user, string $query = '')
{
    return test()->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('security').$query);
}

/** Platform staff, who may open /admin. */
function signInSurfacesStaff(): User
{
    return User::factory()->platformAdmin('super_admin')->create(['name' => 'Layla Haddad']);
}

/** A business that is a real one (not a test or demo workspace), with its owner. @return array{0: User, 1: Tenant} */
function signInSurfacesBusiness(string $name = 'Al-Fares Electronics'): array
{
    [$owner, $tenant] = makeAccount(['name' => 'Omar Fares', 'email' => 'omar@alfares.test'], ['name' => $name]);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    return [$owner, $tenant];
}

/** A new member of the business. */
function signInSurfacesMember(Tenant $tenant, string $name, string $role = 'collector'): User
{
    $member = User::factory()->create(['name' => $name, 'email' => str($name)->slug().'@alfares.test']);
    $tenant->users()->attach($member->id, ['role' => $role]);

    return $member;
}

beforeEach(function () {
    $this->travelTo('2026-11-02 09:30:00');
});

describe('the API', function () {
    it('is for signed-in people only', function () {
        $this->getJson('/api/v1/account/sign-ins')->assertUnauthorized();
    });

    it('sits behind the same guards and the same throttle as /me', function () {
        $served = fn (string $name): array => Route::getRoutes()->getByName($name)->gatherMiddleware();

        expect($served('api.account.sign-ins'))->toBe($served('api.me'));
    });

    it('sends the last 20 of the person, newest first, and nobody else’s', function () {
        [$me] = apiOwner();
        $mine = signInSurfacesHistory($me, 25);
        $someoneElse = User::factory()->create();
        $theirs = signInSurfacesHistory($someoneElse, 3, 'Other');

        $ids = collect($this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe(collect(array_slice($mine, 0, 20))->pluck('id')->all())
            ->and(array_intersect($ids, collect($theirs)->pluck('id')->all()))->toBe([]);
    });

    it('sends every field with its type, the moment in UTC, and nothing from the user agent', function () {
        [$me] = apiOwner();
        signInSurfacesRow($me, [
            'channel' => 'app', 'outcome' => 'failed', 'device_type' => 'tablet', 'os' => 'Android', 'browser' => null,
            'app_version' => '1.29.0', 'device_name' => 'Galaxy Tab S9', 'ip' => '198.51.100.7', 'ip_country' => 'SA',
            'proxy' => 'detected', 'proxy_signals' => ['via_header', 'forwarded_chain'], 'user_agent' => 'Dart/3.5 (dart:io)',
            'created_at' => now()->subMinutes(5),
        ]);

        $row = $this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data.0');

        expect(array_keys($row))->toBe(SIGN_IN_SURFACES_KEYS)
            ->and($row['id'])->toBeString()
            ->and($row)->toMatchArray([
                'method' => 'password', 'channel' => 'app', 'outcome' => 'failed', 'device_type' => 'tablet', 'os' => 'Android',
                'browser' => null, 'app_version' => '1.29.0', 'device_name' => 'Galaxy Tab S9', 'ip' => '198.51.100.7', 'ip_country' => 'SA',
                'proxy' => 'detected', 'proxy_signals' => ['via_header', 'forwarded_chain'], 'created_at' => '2026-11-02T09:25:00Z',
            ])
            ->and(json_encode($row))->not->toContain('Dart/3.5');
    });

    it('is described in docs/api/openapi.yaml with exactly the fields it sends', function () {
        [$me] = apiOwner();
        signInSurfacesRow($me);

        $sent = array_keys($this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data.0'));

        expect(signInSurfacesDocumentedProperties())->toBe($sent)->and($sent)->toBe(SIGN_IN_SURFACES_KEYS);
    });

    it('sends nulls and an empty list where nothing is known', function () {
        [$me] = apiOwner();
        signInSurfacesRow($me, ['os' => null, 'browser' => null, 'ip_country' => null, 'device_type' => 'unknown']);

        $row = $this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data.0');

        expect($row)->toMatchArray(['os' => null, 'browser' => null, 'app_version' => null, 'device_name' => null, 'ip_country' => null, 'proxy_signals' => [], 'device_type' => 'unknown']);
    });

    it('sends an empty list for a person with no record', function () {
        apiOwner();

        $this->getJson('/api/v1/account/sign-ins')->assertOk()->assertExactJson(['data' => []]);
    });
});

describe('the security page', function () {
    it('lists the last 20 sign-ins of the person, newest first, and nobody else’s', function () {
        [$me] = owner();
        signInSurfacesHistory($me, 25);
        signInSurfacesHistory(User::factory()->create(), 3, 'Other');

        $page = signInSurfacesSecurityPage($me)->assertOk()
            ->assertSee(__('Recent sign-ins'))
            ->assertSeeInOrder(['Browser01', 'Browser02', 'Browser10', 'Browser19', 'Browser20'])
            ->assertDontSee('Browser21')->assertDontSee('Browser25')
            ->assertDontSee('Other01');

        expect(substr_count($page->getContent(), 'class="signin-row"'))->toBe(20);
    });

    it('shows each row as a device line, the proxy verdict and when', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['device_type' => 'mobile', 'os' => 'Android', 'browser' => 'Chrome', 'ip_country' => 'QA', 'created_at' => now()->subMinutes(5)]);

        signInSurfacesSecurityPage($me)->assertOk()
            ->assertSee('Mobile')->assertSee('Chrome on Android')->assertSee('Qatar')
            ->assertSee(__('Proxy not detected'))
            ->assertSee('5 minutes ago')
            // The exact time, on the clock of the person's own business (Saudi Arabia, UTC+3), as the tooltip.
            ->assertSee('title="2 Nov 2026, 12:25', false)
            ->assertSee('datetime="2026-11-02T09:25:00+00:00"', false);
    });

    it('says the device in words for every kind of device', function (string $type, string $word) {
        [$me] = owner();
        signInSurfacesRow($me, ['device_type' => $type, 'os' => null, 'browser' => null, 'ip_country' => null]);

        signInSurfacesSecurityPage($me)->assertOk()->assertSee($word);
    })->with([['desktop', 'Desktop'], ['mobile', 'Mobile'], ['tablet', 'Tablet'], ['unknown', 'Unknown device']]);

    it('says the proxy verdict in words, and what was seen in plain words, never a raw signal name', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['proxy' => 'detected', 'proxy_signals' => ['tor', 'via_header', 'forwarded_chain'], 'created_at' => now()->subMinutes(3)]);
        signInSurfacesRow($me, ['proxy' => 'possible', 'proxy_signals' => ['timezone_country_mismatch'], 'created_at' => now()->subMinutes(2)]);
        signInSurfacesRow($me, ['proxy' => 'not_detected', 'proxy_signals' => [], 'created_at' => now()->subMinutes(1)]);

        $html = signInSurfacesSecurityPage($me)->assertOk()
            ->assertSee(__('Proxy detected'))->assertSee(__('Proxy possible'))->assertSee(__('Proxy not detected'))
            ->assertSee(__('the Tor network'))->assertSee(__('a proxy announcing itself'))->assertSee(__('more forwarding steps than usual'))
            ->assertSee(__('the device’s time zone does not match the connection’s country'))
            ->assertDontSee('via_header')->assertDontSee('forwarded_chain')->assertDontSee('timezone_country_mismatch')
            ->assertDontSee('certain')->getContent();

        // One hint for each of the two rows that has something to say, none for the quiet row.
        expect(substr_count($html, 'class="signin-hint'))->toBe(2);
    });

    it('marks a wrong password in words, not only in colour', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['outcome' => 'failed', 'created_at' => now()->subMinutes(2)]);
        signInSurfacesRow($me, ['outcome' => 'success', 'browser' => 'Firefox', 'created_at' => now()->subMinutes(1)]);

        $html = signInSurfacesSecurityPage($me)->assertOk()->assertSee(__('Wrong password'))->getContent();

        expect(substr_count($html, __('Wrong password')))->toBe(1);
    });

    it('shows the device name and the app version for a sign-in from the phone app', function () {
        [$me] = owner();
        signInSurfacesRow($me, [
            'channel' => 'app', 'device_type' => 'mobile', 'os' => 'Android', 'browser' => null, 'device_name' => 'Pixel 8', 'app_version' => '1.29.0', 'ip_country' => 'AE',
        ]);

        signInSurfacesSecurityPage($me)->assertOk()
            ->assertSee('Pixel 8')->assertSee('1.29.0')->assertSee('United Arab Emirates');
    });

    it('never shows the user agent, and writes every value of a row escaped', function () {
        [$me] = owner();
        signInSurfacesRow($me, [
            'browser' => '<script>alert(1)</script>', 'os' => '"><img src=x onerror=alert(2)>', 'device_name' => '<b>Pwned</b>',
            'user_agent' => 'Mozilla/5.0 SECRET-USER-AGENT-TEXT',
        ]);

        signInSurfacesSecurityPage($me)->assertOk()
            ->assertDontSee('SECRET-USER-AGENT-TEXT')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<img src=x', false)->assertDontSee('<b>Pwned</b>', false);
    });

    it('does not print the address of the connection (the country is enough for the person)', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['ip' => '198.51.100.77']);

        signInSurfacesSecurityPage($me)->assertOk()->assertSee(__('Recent sign-ins'))->assertSee('Chrome on Windows')->assertDontSee('198.51.100.77');
    });

    it('says so in a sentence when there is nothing yet', function () {
        [$me] = owner();

        signInSurfacesSecurityPage($me)->assertOk()
            ->assertSee(__('Recent sign-ins'))->assertSee(__('No sign-ins are recorded yet.'))
            ->assertDontSee('class="signin-row"', false);
    });

    it('leaves the country out when it is not known, and never prints a code or a placeholder for it', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['ip_country' => null, 'browser' => 'Firefox', 'os' => 'Linux']);
        signInSurfacesRow($me, ['ip_country' => 'ZZ', 'browser' => 'Safari', 'os' => 'macOS', 'created_at' => now()->subMinute()]);

        signInSurfacesSecurityPage($me)->assertOk()->assertSee('Firefox on Linux')->assertSee('Safari on macOS')
            ->assertDontSee('Unknown Region')->assertDontSee('ZZ');
    });

    it('works for the admin team too, who have no workspace and whose clock is UTC', function () {
        $staff = signInSurfacesStaff();
        signInSurfacesRow($staff, ['created_at' => now()->subMinutes(5)]);

        signInSurfacesSecurityPage($staff)->assertOk()->assertSee('title="2 Nov 2026, 09:25', false);
    });

    it('still asks for the password again first, and sends guests to sign in', function () {
        [$me] = owner();
        signInSurfacesRow($me);

        $this->get(route('security'))->assertRedirect(route('login'));
        $this->actingAs($me)->get(route('security'))->assertRedirect(route('password.confirm'));
    });

    it('reads right to left in Arabic, in Arabic', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['device_type' => 'mobile', 'proxy' => 'possible', 'proxy_signals' => ['timezone_country_mismatch'], 'created_at' => now()->subMinutes(5)]);
        signInSurfacesRow($me, ['outcome' => 'failed', 'created_at' => now()->subMinutes(9)]);

        signInSurfacesSecurityPage($me, '?lang=ar')->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee(trans('Recent sign-ins', [], 'ar'))->assertSee(trans('Proxy possible', [], 'ar'))
            ->assertSee(trans('Wrong password', [], 'ar'))->assertSee(trans('Mobile', [], 'ar'))
            ->assertSee(Carbon::now()->subMinutes(5)->locale('ar')->diffForHumans())
            ->assertSee(Countries::name('QA', 'ar'))
            ->assertDontSee('Recent sign-ins')->assertDontSee('Proxy possible')->assertDontSee('Wrong password')->assertDontSee('Qatar');
    });
});

describe('the business page for platform staff', function () {
    it('shows each member’s recent sign-ins, with the address staff need', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        $sara = signInSurfacesMember($tenant, 'Sara Khalid');
        signInSurfacesRow($owner, ['browser' => 'OwnerBrowser', 'ip' => '203.0.113.10', 'created_at' => now()->subMinutes(5)]);
        signInSurfacesRow($sara, ['device_type' => 'mobile', 'os' => 'iOS', 'browser' => 'Mobile Safari', 'ip' => '198.51.100.20', 'proxy' => 'possible', 'proxy_signals' => ['timezone_country_mismatch'], 'outcome' => 'failed']);

        $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSee(__('Recent sign-ins'))
            ->assertSeeInOrder(['Omar Fares', 'OwnerBrowser', '203.0.113.10', 'Sara Khalid', 'Mobile Safari on iOS', '198.51.100.20'])
            ->assertSee(__('Proxy possible'))->assertSee(__('Wrong password'))->assertSee('Qatar')
            ->assertDontSee(__('No sign-ins are recorded for the people in this business yet.'));
    });

    it('shows each member’s last 10 only, newest first, and no other business’s people', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        $sara = signInSurfacesMember($tenant, 'Sara Khalid');
        signInSurfacesHistory($owner, 12, 'Own');
        signInSurfacesHistory($sara, 12, 'Sara');
        [$strangerOwner] = makeAccount(['name' => 'Someone Else'], ['name' => 'Another Shop']);
        signInSurfacesHistory($strangerOwner, 3, 'Stranger');

        $page = $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSeeInOrder(['Own01', 'Own02', 'Own10'])->assertDontSee('Own11')->assertDontSee('Own12')
            ->assertSeeInOrder(['Sara01', 'Sara02', 'Sara10'])->assertDontSee('Sara11')->assertDontSee('Sara12')
            ->assertDontSee('Stranger01')->assertDontSee('Someone Else');

        expect(substr_count($page->getContent(), 'class="signin-row"'))->toBe(20);
    });

    it('reads the sign-ins in one query, however many people the business has', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesHistory($owner, 12, 'Own');
        $staff = signInSurfacesStaff();

        $reads = function () use ($staff, $tenant): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->actingAs($staff)->get(route('admin.businesses.show', $tenant))->assertOk();
            DB::disableQueryLog();

            return collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'sign_in_events'))->count();
        };

        $alone = $reads();
        foreach (['Sara Khalid', 'Huda Saleh', 'Nour Ali', 'Karim Hamdan', 'Dina Mansour'] as $name) {
            signInSurfacesHistory(signInSurfacesMember($tenant, $name), 12, str($name)->before(' ')->toString());
        }

        expect($alone)->toBe(1)->and($reads())->toBe(1);
    });

    it('says so in one sentence when nobody in the business has a record', function () {
        [, $tenant] = signInSurfacesBusiness();
        signInSurfacesMember($tenant, 'Sara Khalid');

        $page = $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSee(__('Recent sign-ins'))->assertSee(__('No sign-ins are recorded for the people in this business yet.'));

        expect(substr_count($page->getContent(), 'class="signin-row"'))->toBe(0);
    });

    it('says so for a member with no record while another has some', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesMember($tenant, 'Sara Khalid');
        signInSurfacesRow($owner, ['browser' => 'OwnerBrowser']);

        $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSeeInOrder(['Omar Fares', 'OwnerBrowser', 'Sara Khalid', __('No sign-ins recorded.')])
            ->assertDontSee(__('No sign-ins are recorded for the people in this business yet.'));
    });

    it('writes everything escaped', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesMember($tenant, '<i>Mallory</i>');
        signInSurfacesRow($owner, ['browser' => '<script>alert(1)</script>', 'device_name' => '<b>x</b>', 'user_agent' => 'SECRET-USER-AGENT-TEXT']);

        $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<i>Mallory</i>', false)->assertDontSee('<b>x</b>', false)
            ->assertDontSee('SECRET-USER-AGENT-TEXT');
    });

    it('is not there for anyone who is not platform staff: the usual refusal', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        $member = signInSurfacesMember($tenant, 'Sara Khalid');
        signInSurfacesRow($owner, ['ip' => '203.0.113.10']);
        signInSurfacesRow($member, ['ip' => '198.51.100.20']);

        $this->get(route('admin.businesses.show', $tenant))->assertRedirect(route('login'));
        $this->actingAs($owner)->get(route('admin.businesses.show', $tenant))->assertNotFound();
        $this->actingAs($member)->get(route('admin.businesses.show', $tenant))->assertNotFound();
    });

    it('still does not open a test or demo workspace', function () {
        $test = Tenant::factory()->create(['is_test' => true]);

        $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $test))->assertNotFound();
    });

    it('reads right to left in Arabic', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesRow($owner, ['proxy' => 'detected', 'proxy_signals' => ['via_header'], 'created_at' => now()->subMinutes(5)]);

        $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant).'?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(trans('Recent sign-ins', [], 'ar'))->assertSee(trans('Proxy detected', [], 'ar'))
            ->assertSee(Carbon::now()->subMinutes(5)->locale('ar')->diffForHumans())
            ->assertDontSee('Recent sign-ins')->assertDontSee('Proxy detected');
    });
});

describe('the wording, in one place', function () {
    it('joins the device, the system, the browser and the country', function (array $row, string $line) {
        $event = new SignInEvent($row + ['device_type' => 'desktop', 'os' => null, 'browser' => null, 'app_version' => null, 'device_name' => null, 'ip_country' => null, 'proxy' => 'not_detected', 'proxy_signals' => []]);
        $event->created_at = now()->subMinutes(5);

        expect(SignInPresenter::present($event, 'UTC')['device'])->toBe($line);
    })->with([
        'a browser on a system' => [['device_type' => 'mobile', 'os' => 'Android', 'browser' => 'Chrome', 'ip_country' => 'QA'], 'Mobile · Chrome on Android · Qatar'],
        'only the browser' => [['browser' => 'Firefox'], 'Desktop · Firefox'],
        'only the system' => [['os' => 'Linux', 'ip_country' => 'AE'], 'Desktop · Linux · United Arab Emirates'],
        'nothing known' => [['device_type' => 'unknown'], 'Unknown device'],
        'the phone app' => [['channel' => 'app', 'device_type' => 'mobile', 'device_name' => 'Pixel 8', 'os' => 'Android', 'app_version' => '1.29.0', 'ip_country' => 'SA'], 'Pixel 8 · Android · App 1.29.0 · Saudi Arabia'],
        'the phone app that told nothing about itself' => [['channel' => 'app', 'device_type' => 'tablet'], 'Tablet · App'],
    ]);

    it('names a country in the language asked, and nothing for a code that is not one', function () {
        expect(Countries::name('QA', 'en'))->toBe('Qatar')
            ->and(Countries::name('QA', 'ar'))->toBe('قطر')
            ->and(Countries::name('SA'))->toBe('Saudi Arabia')
            ->and(Countries::name(null))->toBeNull()
            ->and(Countries::name(''))->toBeNull()
            ->and(Countries::name('ZZ'))->toBeNull()
            ->and(Countries::name('T1'))->toBeNull()
            ->and(Countries::name('Qatar'))->toBeNull();
    });
});
