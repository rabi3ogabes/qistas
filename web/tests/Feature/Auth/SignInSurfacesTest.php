<?php

use App\Auth\SignInPresenter;
use App\Models\AuditLog;
use App\Models\SignInEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

/**
 * A stranger guessing at [$user]'s account from 30 different addresses (so the one-a-minute throttle per address lets
 * every guess through), newest guess 10 seconds ago, and three real sign-ins around them: Good1 25 seconds ago (between
 * the second and third guess), Good2 4 minutes ago, Good3 5 minutes ago. The browser names the rows: Bad01 is the newest
 * guess, Good1 the newest real sign-in.
 */
function signInSurfacesFlood(User $user): void
{
    for ($i = 1; $i <= 30; $i++) {
        signInSurfacesRow($user, ['outcome' => 'failed', 'browser' => sprintf('Bad%02d', $i), 'ip' => '198.51.100.'.$i, 'created_at' => now()->subSeconds($i * 10)]);
    }
    signInSurfacesRow($user, ['browser' => 'Good1', 'created_at' => now()->subSeconds(25)]);
    signInSurfacesRow($user, ['browser' => 'Good2', 'created_at' => now()->subMinutes(4)]);
    signInSurfacesRow($user, ['browser' => 'Good3', 'created_at' => now()->subMinutes(5)]);
}

/**
 * What the shared list must give for one person's [$rows], worked out here in plain PHP, independently of the SQL: the
 * newest [$failedCap] wrong passwords and the newest [$limit] real sign-ins, merged newest first (the id breaks a tie),
 * the top [$limit] of them.
 *
 * @param  list<SignInEvent>  $rows
 * @return list<string> ids
 */
function signInSurfacesExpectedIds(array $rows, int $limit, int $failedCap): array
{
    $newest = fn (Collection $rows): Collection => $rows
        ->sort(fn (SignInEvent $a, SignInEvent $b) => [$b->created_at->getTimestamp(), $b->id] <=> [$a->created_at->getTimestamp(), $a->id])
        ->values();

    $failed = $newest(collect($rows)->where('outcome', 'failed'))->take($failedCap);
    $real = $newest(collect($rows)->where('outcome', '!=', 'failed'))->take($limit);

    return $newest($failed->merge($real))->take($limit)->pluck('id')->all();
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

    it('sends the person’s 20 latest sign-ins, newest first, and nobody else’s', function () {
        [$me] = apiOwner();
        $mine = signInSurfacesHistory($me, 25);
        $someoneElse = User::factory()->create();
        $theirs = signInSurfacesHistory($someoneElse, 3, 'Other');

        $ids = collect($this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe(collect(array_slice($mine, 0, 20))->pluck('id')->all())
            ->and(array_intersect($ids, collect($theirs)->pluck('id')->all()))->toBe([]);
    });

    it('shows a flood of wrong passwords as the newest 5 of them, and every real sign-in with them', function () {
        [$me] = apiOwner();
        signInSurfacesFlood($me);
        signInSurfacesRow(User::factory()->create(), ['browser' => 'Stranger', 'created_at' => now()->subSeconds(1)]);

        $browsers = collect($this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data'))->pluck('browser')->all();

        expect($browsers)->toBe(['Bad01', 'Bad02', 'Good1', 'Bad03', 'Bad04', 'Bad05', 'Good2', 'Good3']);
    });

    it('fills the 20 with real sign-ins: at most 5 wrong passwords among them', function () {
        [$me] = apiOwner();
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = signInSurfacesRow($me, ['browser' => 'Real'.$i, 'created_at' => now()->subSeconds($i * 60)]);
        }
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = signInSurfacesRow($me, ['outcome' => 'failed', 'browser' => 'Guess'.$i, 'created_at' => now()->subSeconds($i * 60 - 30)]);
        }

        $sent = collect($this->getJson('/api/v1/account/sign-ins')->assertOk()->json('data'));

        expect($sent)->toHaveCount(20)
            ->and($sent->where('outcome', 'failed'))->toHaveCount(5)
            ->and($sent->pluck('id')->all())->toBe(signInSurfacesExpectedIds($rows, 20, 5));
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
    it('lists the person’s 20 latest sign-ins, newest first, and nobody else’s', function () {
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

    it('shows a flood of wrong passwords as the newest 5 of them, and every real sign-in', function () {
        [$me] = owner();
        signInSurfacesFlood($me);

        $page = signInSurfacesSecurityPage($me)->assertOk()
            ->assertSeeInOrder(['Bad01', 'Bad02', 'Good1', 'Bad03', 'Bad04', 'Bad05', 'Good2', 'Good3'])
            ->assertDontSee('Bad06')->assertDontSee('Bad30');

        expect(substr_count($page->getContent(), 'class="signin-row"'))->toBe(8)
            ->and(substr_count($page->getContent(), __('Wrong password')))->toBe(5);
    });

    it('prints the day beside "ago" for a sign-in older than a day, with the exact time still in the title', function () {
        [$me] = owner();
        signInSurfacesRow($me, ['browser' => 'Recent', 'created_at' => now()->subHours(23)]);
        signInSurfacesRow($me, ['browser' => 'Older', 'created_at' => now()->subHours(25)]);
        signInSurfacesRow($me, ['browser' => 'Oldest', 'created_at' => now()->subDays(3)]);

        $html = signInSurfacesSecurityPage($me)->assertOk()
            ->assertSee('class="signin-day">1 Nov 2026<', false)
            ->assertSee('class="signin-day">30 Oct 2026<', false)
            ->assertSee('title="30 Oct 2026, 12:30', false)
            ->getContent();

        expect(substr_count($html, 'class="signin-day"'))->toBe(2);
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

    it('shows a flood of wrong passwords as the newest 3 of them, and every real sign-in', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesFlood($owner);

        $page = $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSeeInOrder(['Bad01', 'Bad02', 'Good1', 'Bad03', 'Good2', 'Good3'])
            ->assertDontSee('Bad04')->assertDontSee('Bad05')->assertDontSee('Bad30')
            ->assertSee('198.51.100.1');

        expect(substr_count($page->getContent(), 'class="signin-row"'))->toBe(6)
            ->and(substr_count($page->getContent(), __('Wrong password')))->toBe(3);
    });

    it('writes one audit row when it shows the sign-ins, with the numbers and nothing from them', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        $sara = signInSurfacesMember($tenant, 'Sara Khalid');
        signInSurfacesMember($tenant, 'Nour Ali');
        signInSurfacesHistory($owner, 12, 'Own');
        signInSurfacesHistory($sara, 2, 'Sara');
        $staff = signInSurfacesStaff();

        $this->actingAs($staff)->get(route('admin.businesses.show', $tenant))->assertOk();

        $audit = AuditLog::query()->where('action', 'admin.sign_ins_viewed')->sole();

        // 12 rows of the owner are cut to 10, and the member with none is not counted among the people shown.
        expect($audit->tenant_id)->toBe($tenant->id)->and($audit->user_id)->toBe($staff->id)
            ->and($audit->subject_id)->toBe($tenant->id)->and($audit->subject_type)->toBe($tenant->getMorphClass())
            ->and($audit->changes)->toBe(['people' => 2, 'rows' => 12])
            ->and(json_encode($audit->getAttributes()))->not->toContain('203.0.113.9')->not->toContain('Chrome');
    });

    it('writes one audit row for each time the page is opened, not for each row', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesHistory($owner, 8, 'Own');
        $staff = signInSurfacesStaff();

        $this->actingAs($staff)->get(route('admin.businesses.show', $tenant))->assertOk();
        expect(AuditLog::query()->where('action', 'admin.sign_ins_viewed')->count())->toBe(1);

        $this->actingAs($staff)->get(route('admin.businesses.show', $tenant))->assertOk();
        expect(AuditLog::query()->where('action', 'admin.sign_ins_viewed')->count())->toBe(2);
    });

    it('writes no audit row when the business has no record to show', function () {
        [, $tenant] = signInSurfacesBusiness();

        $this->actingAs(signInSurfacesStaff())->get(route('admin.businesses.show', $tenant))->assertOk();

        expect(AuditLog::query()->where('action', 'admin.sign_ins_viewed')->count())->toBe(0);
    });

    it('writes no audit row for a customer-side user who is refused', function () {
        [$owner, $tenant] = signInSurfacesBusiness();
        signInSurfacesRow($owner);

        $this->actingAs($owner)->get(route('admin.businesses.show', $tenant))->assertNotFound();

        expect(AuditLog::query()->where('action', 'admin.sign_ins_viewed')->count())->toBe(0);
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

describe('the shared list of recent sign-ins', function () {
    it('gives each person their newest wrong passwords up to the cap, and real sign-ins to fill the rest', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $rowsA = $rowsB = [];
        for ($i = 1; $i <= 12; $i++) {
            $rowsA[] = signInSurfacesRow($a, ['outcome' => 'failed', 'created_at' => now()->subSeconds($i * 7)]);
            $rowsB[] = signInSurfacesRow($b, ['outcome' => $i % 2 === 0 ? 'failed' : 'success', 'created_at' => now()->subSeconds($i * 11)]);
        }
        for ($i = 1; $i <= 9; $i++) {
            $rowsA[] = signInSurfacesRow($a, ['created_at' => now()->subSeconds($i * 13)]);
            $rowsB[] = signInSurfacesRow($b, ['created_at' => now()->subSeconds($i * 17)]);
        }

        $lists = SignInEvent::recent([$a->id, $b->id], 10, 3);

        expect($lists->keys()->all())->toEqualCanonicalizing([$a->id, $b->id])
            ->and($lists[$a->id]->pluck('id')->all())->toBe(signInSurfacesExpectedIds($rowsA, 10, 3))
            ->and($lists[$b->id]->pluck('id')->all())->toBe(signInSurfacesExpectedIds($rowsB, 10, 3))
            ->and($lists[$a->id]->where('outcome', 'failed'))->toHaveCount(3)
            ->and($lists[$b->id]->where('outcome', 'failed'))->toHaveCount(3);
    });

    it('never mixes in a person who was not asked for', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $mine = signInSurfacesHistory($a, 4, 'Mine');
        signInSurfacesHistory($b, 4, 'Theirs');

        $lists = SignInEvent::recent([$a->id], 20, 5);

        expect($lists->keys()->all())->toBe([$a->id])
            ->and($lists[$a->id]->pluck('id')->all())->toBe(collect($mine)->pluck('id')->all());
    });

    it('keeps rows with the very same moment in one stable order: the id decides, as it does for the person’s own relation', function () {
        $a = User::factory()->create();
        $moment = now()->subMinutes(3);
        $same = [];
        for ($i = 0; $i < 12; $i++) {
            $same[] = signInSurfacesRow($a, ['outcome' => $i % 3 === 0 ? 'failed' : 'success', 'created_at' => $moment]);
        }

        $first = SignInEvent::recent([$a->id], 8, 2)[$a->id]->pluck('id')->all();
        $again = SignInEvent::recent([$a->id], 8, 2)[$a->id]->pluck('id')->all();

        expect($first)->toBe($again)->toBe(signInSurfacesExpectedIds($same, 8, 2))
            // With room for all of them the order is the one User::signInEvents() reads in.
            ->and(SignInEvent::recent([$a->id], 12, 12)[$a->id]->pluck('id')->all())->toBe($a->signInEvents()->pluck('id')->all());
    });

    it('can give a person only wrong passwords, up to the cap, and none when the cap is 0', function () {
        $a = User::factory()->create();
        for ($i = 1; $i <= 8; $i++) {
            signInSurfacesRow($a, ['outcome' => 'failed', 'created_at' => now()->subMinutes($i)]);
        }

        expect(SignInEvent::recent([$a->id], 20, 5)[$a->id])->toHaveCount(5)
            ->and(SignInEvent::recent([$a->id], 20, 0))->toBeEmpty();
    });

    it('takes ids as a list or as a collection, ignores repeats, and asks nothing for no one', function () {
        $a = User::factory()->create();
        signInSurfacesRow($a);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $none = SignInEvent::recent([], 20, 5);
        $zero = SignInEvent::recent([$a->id], 0, 5);
        DB::disableQueryLog();

        expect($none)->toBeEmpty()->and($zero)->toBeEmpty()->and(DB::getQueryLog())->toBe([])
            ->and(SignInEvent::recent(collect([$a->id, $a->id]), 20, 5)[$a->id])->toHaveCount(1);
    });

    it('is one query with only bound parameters: ids and numbers are never written into the SQL', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        signInSurfacesRow($a);

        DB::flushQueryLog();
        DB::enableQueryLog();
        SignInEvent::recent([$a->id, $b->id], 17, 4);
        DB::disableQueryLog();
        $queries = DB::getQueryLog();

        expect($queries)->toHaveCount(1)
            ->and($queries[0]['query'])->not->toContain($a->id)->not->toContain($b->id)->not->toContain('17')->not->toContain('failed')
            ->and($queries[0]['bindings'])->toContain($a->id)->toContain($b->id)->toContain(17)->toContain(4)
            // Standard window functions only (SQLite and PostgreSQL both run them).
            ->and($queries[0]['query'])->toContain('ROW_NUMBER() OVER (PARTITION BY user_id, outcome ORDER BY created_at DESC, id DESC)');
    });

    it('hands the person’s own list to the API and the page through one call', function () {
        $a = User::factory()->create();
        signInSurfacesFlood($a);

        expect(SignInEvent::recentFor($a)->pluck('browser')->all())->toBe(['Bad01', 'Bad02', 'Good1', 'Bad03', 'Bad04', 'Bad05', 'Good2', 'Good3'])
            ->and(SignInEvent::recentFor(User::factory()->create()))->toBeEmpty();
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

    it('skips a signal it has no words for, and has no hint when none is left', function () {
        $event = new SignInEvent(['device_type' => 'desktop', 'proxy' => 'detected', 'proxy_signals' => ['future_signal', 'tor', 'tor']]);
        $event->created_at = now()->subMinutes(5);
        $only = new SignInEvent(['device_type' => 'desktop', 'proxy' => 'possible', 'proxy_signals' => ['future_signal']]);
        $only->created_at = now()->subMinutes(5);
        $quiet = new SignInEvent(['device_type' => 'desktop', 'proxy' => 'not_detected', 'proxy_signals' => ['tor']]);
        $quiet->created_at = now()->subMinutes(5);

        expect(SignInPresenter::present($event)['hint'])->toBe(__('What we noticed: :signals', ['signals' => __('the Tor network')]))
            ->and(SignInPresenter::present($only)['hint'])->toBeNull()
            ->and(SignInPresenter::present($quiet)['hint'])->toBeNull();
    });

    it('writes the exact time in UTC when it is given a zone that does not exist', function () {
        $event = new SignInEvent(['device_type' => 'desktop', 'proxy' => 'not_detected', 'proxy_signals' => []]);
        $event->created_at = now()->subMinutes(5);

        $utc = SignInPresenter::present($event, 'UTC')['exact'];

        expect($utc)->toStartWith('2 Nov 2026, 09:25 (')
            ->and(SignInPresenter::present($event, 'Not/AZone')['exact'])->toBe($utc)
            ->and(SignInPresenter::present($event, '')['exact'])->toBe($utc)
            ->and(SignInPresenter::present($event, 'Asia/Riyadh')['exact'])->toStartWith('2 Nov 2026, 12:25 (');
    });

    it('gives the day only for a sign-in older than a day', function () {
        $event = new SignInEvent(['device_type' => 'desktop', 'proxy' => 'not_detected', 'proxy_signals' => []]);

        $event->created_at = now()->subHours(23);
        $recent = SignInPresenter::present($event, 'Asia/Riyadh')['day'];
        $event->created_at = now()->subHours(25);
        $older = SignInPresenter::present($event, 'Asia/Riyadh')['day'];

        expect($recent)->toBeNull()->and($older)->toBe('1 Nov 2026');
    });

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
