<?php

use App\Errors\ErrorEvent;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorTriage;
use App\Errors\Recorded;
use App\Models\AuditLog;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use App\Models\User;
use App\Reports\PlatformOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| The Errors screens of the admin console (design section 7): the list, the detail, the status buttons, the menu badge and
| the line on the admin home. Only platform staff may see any of it; a customer gets a 404 as everywhere in /admin. What
| is stored is already scrubbed, but the views escape everything anyway, and a test below proves it with markup in every
| stored field.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30:00', 'UTC'));
    // An error page (the 404s and 422s below) is drawn by the production pages, not by the debug renderer, which takes seconds.
    config(['app.debug' => false]);
});

function errorsAdminUser(string $role = 'admin'): User
{
    return User::factory()->platformAdmin($role)->create();
}

/** An issue straight into the table, the way the recorder writes one; $days is ['2026-11-04' => 3, ...]. */
function errorsAdminIssue(array $attributes = [], array $days = []): ErrorIssue
{
    $issue = ErrorIssue::query()->forceCreate($attributes + [
        'fingerprint' => sha1(Str::random(32)),
        'source' => 'site',
        'kind' => 'exception',
        'title' => 'RuntimeException: something broke',
        'class' => 'RuntimeException',
        'status' => 'open',
        'occurrences' => 1,
        'people' => 0,
        'first_seen_at' => now()->subDays(3),
        'last_seen_at' => now()->subHour(),
        'first_version' => '1.28.0',
        'last_version' => '1.28.0',
    ]);

    foreach ($days as $day => $count) {
        DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => $day, 'count' => $count]);
    }

    return $issue;
}

function errorsAdminSample(ErrorIssue $issue, array $attributes = []): ErrorOccurrence
{
    return ErrorOccurrence::query()->create($attributes + [
        'issue_id' => $issue->id,
        'occurred_at' => now()->subHour(),
        'status' => 500,
        'method' => 'GET',
        'route' => '/app/customers/{id}',
        'message' => 'Call to a member function format() on null',
        'trace' => "#0 app/Http/Controllers/CustomerController.php(41): format()\n#1 [internal function]: show()",
        'version' => '1.28.0',
        'platform' => 'server',
        'device' => null,
    ]);
}

/** One row of the list, or '' when the issue is not on the page. */
function errorsAdminRow(string $html, ErrorIssue $issue): string
{
    preg_match('/<tr[^>]*data-issue="'.preg_quote($issue->id, '/').'".*?<\/tr>/s', $html, $match);

    return $match[0] ?? '';
}

/** How many issues the list shows. */
function errorsAdminRows(string $html): int
{
    return preg_match_all('/<tr[^>]*data-issue="/', $html);
}

/** The admin menu panel's markup. */
function errorsAdminRail(string $html): string
{
    preg_match('/<aside[^>]*id="admin-nav".*?<\/aside>/s', $html, $match);

    return $match[0] ?? '';
}

function errorsAdminQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

describe('who may look and who may change', function () {
    it('sends a guest to sign in, on the list, the detail and the status buttons', function () {
        $issue = errorsAdminIssue();

        $this->get('/admin/errors')->assertRedirect(route('login'));
        $this->get('/admin/errors/'.$issue->id)->assertRedirect(route('login'));
        $this->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertRedirect(route('login'));

        expect($issue->fresh()->status)->toBe('open');
    });

    it('shows a customer nothing: a 404 on every route, and nothing changes', function () {
        [$customer] = owner();
        $issue = errorsAdminIssue();

        $this->actingAs($customer)->get('/admin/errors')->assertNotFound();
        $this->actingAs($customer)->get('/admin/errors/'.$issue->id)->assertNotFound();
        $this->actingAs($customer)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertNotFound();

        expect($issue->fresh()->status)->toBe('open')->and(AuditLog::query()->where('action', 'like', 'error.%')->count())->toBe(0);
    });

    it('lets both platform roles read and change status', function (string $role) {
        $admin = errorsAdminUser($role);
        $issue = errorsAdminIssue(['title' => 'Boom '.$role]);
        errorsAdminSample($issue);

        $this->actingAs($admin)->get('/admin/errors')->assertOk()->assertSee('Boom '.$role);
        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->assertSee('Boom '.$role);
        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertRedirect(route('admin.errors.show', $issue));

        expect($issue->fresh()->status)->toBe('resolved')->and($issue->fresh()->resolved_by)->toBe($admin->id);
    })->with(['admin', 'super_admin']);

    it('names its routes and holds {issue} to a UUID', function () {
        $issue = errorsAdminIssue();
        $admin = errorsAdminUser();

        expect(route('admin.errors.index', absolute: false))->toBe('/admin/errors')
            ->and(route('admin.errors.show', $issue, false))->toBe('/admin/errors/'.$issue->id)
            ->and(route('admin.errors.status', $issue, false))->toBe('/admin/errors/'.$issue->id.'/status');

        $this->actingAs($admin)->get('/admin/errors/not-a-uuid')->assertNotFound();
        $this->actingAs($admin)->get('/admin/errors/'.Str::uuid())->assertNotFound();
        $this->actingAs($admin)->put('/admin/errors/'.Str::uuid().'/status', ['status' => 'resolved'])->assertNotFound();
    });
});

describe('the list', function () {
    it('has one row per issue, with a source tag in words, the title, the count, the versions and the dates', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([
            'source' => 'api', 'kind' => 'http', 'title' => 'Error 503 on /api/v1/contracts', 'occurrences' => 1204,
            'first_seen_at' => CarbonImmutable::parse('2026-10-30 08:15:00', 'UTC'), 'first_version' => '1.27.0',
            'last_seen_at' => CarbonImmutable::parse('2026-11-04 09:40:00', 'UTC'), 'last_version' => '1.28.0',
        ]);

        $html = $this->actingAs($admin)->get('/admin/errors')->assertOk()->getContent();
        $row = errorsAdminRow($html, $issue);

        expect($row)->toContain('Error 503 on /api/v1/contracts')
            ->toContain('1,204')
            ->toContain('30 Oct 2026')->toContain('1.27.0')
            ->toContain('4 Nov 2026')->toContain('1.28.0')
            ->toMatch('/data-source="api"[^>]*>\s*API\s*</')
            ->toContain('href="'.route('admin.errors.show', $issue).'"');
    });

    it('tags each source in words, not in colour alone', function () {
        $admin = errorsAdminUser();
        $site = errorsAdminIssue(['source' => 'site']);
        $api = errorsAdminIssue(['source' => 'api']);
        $database = errorsAdminIssue(['source' => 'database', 'kind' => 'slow_query']);
        $app = errorsAdminIssue(['source' => 'app', 'kind' => 'crash']);

        $html = $this->actingAs($admin)->get('/admin/errors')->getContent();

        expect(errorsAdminRow($html, $site))->toMatch('/data-source="site"[^>]*>\s*Website\s*</')
            ->and(errorsAdminRow($html, $api))->toMatch('/data-source="api"[^>]*>\s*API\s*</')
            ->and(errorsAdminRow($html, $database))->toMatch('/data-source="database"[^>]*>\s*Database\s*</')
            ->and(errorsAdminRow($html, $app))->toMatch('/data-source="app"[^>]*>\s*App\s*</');
    });

    it('shows the open issues, newest last-seen first', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['title' => 'Oldest sighting', 'last_seen_at' => now()->subDays(2)]);
        errorsAdminIssue(['title' => 'Newest sighting', 'last_seen_at' => now()->subMinutes(5)]);
        errorsAdminIssue(['title' => 'Middle sighting', 'last_seen_at' => now()->subHours(6)]);

        $this->actingAs($admin)->get('/admin/errors')->assertOk()
            ->assertSeeInOrder(['Newest sighting', 'Middle sighting', 'Oldest sighting']);
    });

    it('leaves resolved and ignored issues out unless asked', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['title' => 'Still open']);
        errorsAdminIssue(['title' => 'Already resolved', 'status' => 'resolved', 'resolved_at' => now()]);
        errorsAdminIssue(['title' => 'Silenced for good', 'status' => 'ignored', 'ignored_at' => now()]);

        $this->actingAs($admin)->get('/admin/errors')->assertSee('Still open')->assertDontSee('Already resolved')->assertDontSee('Silenced for good');
        $this->actingAs($admin)->get('/admin/errors?status=resolved')->assertSee('Already resolved')->assertDontSee('Still open')->assertDontSee('Silenced for good');
        $this->actingAs($admin)->get('/admin/errors?status=ignored')->assertSee('Silenced for good')->assertDontSee('Still open')->assertDontSee('Already resolved');
        $this->actingAs($admin)->get('/admin/errors?status=all')->assertSee('Still open')->assertSee('Already resolved')->assertSee('Silenced for good');
    });

    it('removes an issue from the default list once it is ignored', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['title' => 'Noisy but harmless']);

        $this->actingAs($admin)->get('/admin/errors')->assertSee('Noisy but harmless');
        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'ignored'])->assertRedirect();
        $this->actingAs($admin)->get('/admin/errors')->assertDontSee('Noisy but harmless');
    });

    it('filters by source', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['title' => 'From the site', 'source' => 'site']);
        errorsAdminIssue(['title' => 'From the api', 'source' => 'api']);
        errorsAdminIssue(['title' => 'From the database', 'source' => 'database']);
        errorsAdminIssue(['title' => 'From the app', 'source' => 'app', 'kind' => 'crash']);

        foreach (['site', 'api', 'database', 'app'] as $source) {
            $html = $this->actingAs($admin)->get('/admin/errors?source='.$source)->assertOk()->getContent();

            expect(errorsAdminRows($html))->toBe(1)->and($html)->toContain('From the '.$source);
        }
    });

    it('filters to what is new in the last 24 hours', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['title' => 'Brand new', 'first_seen_at' => now()->subHours(2)]);
        errorsAdminIssue(['title' => 'Just inside the day', 'first_seen_at' => now()->subHours(24)]);
        errorsAdminIssue(['title' => 'Just outside the day', 'first_seen_at' => now()->subHours(24)->subSecond()]);
        errorsAdminIssue(['title' => 'Old news', 'first_seen_at' => now()->subDays(9)]);

        $this->actingAs($admin)->get('/admin/errors?new=1')->assertOk()
            ->assertSee('Brand new')->assertSee('Just inside the day')
            ->assertDontSee('Just outside the day')->assertDontSee('Old news');
    });

    it('filters to what came back in the last 7 days', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['title' => 'Came back yesterday', 'reopened_at' => now()->subDay(), 'reopened_version' => '1.29.0']);
        errorsAdminIssue(['title' => 'Came back last month', 'reopened_at' => now()->subDays(30)]);
        errorsAdminIssue(['title' => 'Never came back']);
        errorsAdminIssue(['title' => 'Came back, then resolved again', 'reopened_at' => now()->subDay(), 'status' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($admin)->get('/admin/errors?back=1')->assertOk()
            ->assertSee('Came back yesterday')
            ->assertDontSee('Came back last month')->assertDontSee('Never came back')->assertDontSee('Came back, then resolved again');
    });

    it('combines the filters, and ignores a filter it does not know', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['title' => 'New on the api', 'source' => 'api', 'first_seen_at' => now()->subHour()]);
        errorsAdminIssue(['title' => 'New on the site', 'source' => 'site', 'first_seen_at' => now()->subHour()]);
        errorsAdminIssue(['title' => 'Old on the api', 'source' => 'api']);

        $this->actingAs($admin)->get('/admin/errors?source=api&new=1')->assertOk()
            ->assertSee('New on the api')->assertDontSee('New on the site')->assertDontSee('Old on the api');

        $this->actingAs($admin)->get('/admin/errors?source=nonsense&status=nonsense&new=banana&back=[]')->assertOk()
            ->assertSee('New on the api')->assertSee('Old on the api');
    });

    it('marks what is new, what came back and what is growing, each in words', function () {
        $admin = errorsAdminUser();
        $new = errorsAdminIssue(['title' => 'A new one', 'first_seen_at' => now()->subHours(3)]);
        $back = errorsAdminIssue(['title' => 'A returning one', 'reopened_at' => now()->subDays(2), 'reopened_version' => '1.29.0']);
        $growing = errorsAdminIssue(['title' => 'A growing one'], ['2026-11-04' => 6, '2026-11-02' => 1]);
        $plain = errorsAdminIssue(['title' => 'A plain one'], ['2026-11-04' => 6, '2026-11-03' => 5, '2026-11-02' => 5, '2026-11-01' => 5, '2026-10-31' => 5, '2026-10-30' => 5, '2026-10-29' => 5, '2026-10-28' => 5]);

        $html = $this->actingAs($admin)->get('/admin/errors')->getContent();

        expect(errorsAdminRow($html, $new))->toMatch('/data-mark="new"[^>]*>\s*New\s*</')->not->toContain('data-mark="back"')->not->toContain('data-mark="growing"')
            ->and(errorsAdminRow($html, $back))->toMatch('/data-mark="back"[^>]*>\s*Came back\s*</')->not->toContain('data-mark="new"')
            ->and(errorsAdminRow($html, $growing))->toMatch('/data-mark="growing"[^>]*>\s*Growing\s*</')
            ->and(errorsAdminRow($html, $plain))->not->toContain('data-mark=');
    });

    it('shows the growing mark only on an open issue', function () {
        $admin = errorsAdminUser();
        $days = ['2026-11-04' => 9];
        $open = errorsAdminIssue(['title' => 'Open and growing'], $days);
        $resolved = errorsAdminIssue(['title' => 'Resolved after growing', 'status' => 'resolved', 'resolved_at' => now()], $days);

        $html = $this->actingAs($admin)->get('/admin/errors?status=all')->getContent();

        expect(errorsAdminRow($html, $open))->toContain('data-mark="growing"')->and(errorsAdminRow($html, $resolved))->not->toContain('data-mark="growing"');
    });

    it('does not call an issue that came back more than a week ago, or one that is not open, a returner', function () {
        $admin = errorsAdminUser();
        $stale = errorsAdminIssue(['title' => 'Back long ago', 'reopened_at' => now()->subDays(8)]);

        $html = $this->actingAs($admin)->get('/admin/errors')->getContent();

        expect(errorsAdminRow($html, $stale))->not->toContain('data-mark="back"');
    });

    it('draws a 7-day sparkline as inline SVG, oldest day first, with the numbers in words for a screen reader', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([], ['2026-11-04' => 5, '2026-11-03' => 2, '2026-10-29' => 1]);

        $row = errorsAdminRow($this->actingAs($admin)->get('/admin/errors')->getContent(), $issue);

        expect($row)->toContain('<svg')->toContain('class="err-spark')->toContain('role="img"')
            ->toContain('Last 7 days, oldest first: 1, 0, 0, 0, 0, 2, 5')
            ->and(substr_count($row, '<rect'))->toBe(7);
    });

    it('is paginated at 25', function () {
        $admin = errorsAdminUser();
        foreach (range(1, 30) as $n) {
            errorsAdminIssue(['title' => 'Issue number '.$n, 'last_seen_at' => now()->subMinutes($n)]);
        }

        $first = $this->actingAs($admin)->get('/admin/errors')->assertOk();
        $second = $this->actingAs($admin)->get('/admin/errors?page=2')->assertOk();

        expect(errorsAdminRows($first->getContent()))->toBe(25)
            ->and(errorsAdminRows($second->getContent()))->toBe(5);
        $first->assertSee('Page 1 of 2')->assertSee('rel="next"', false);
        $second->assertSee('Issue number 30');
    });

    it('keeps the filters when it turns the page', function () {
        $admin = errorsAdminUser();
        foreach (range(1, 30) as $n) {
            errorsAdminIssue(['source' => 'api', 'last_seen_at' => now()->subMinutes($n)]);
        }

        $this->actingAs($admin)->get('/admin/errors?source=api')->assertSee('source=api&amp;page=2', false);
    });

    it('says so when nothing is broken, and when a filter finds nothing', function () {
        $admin = errorsAdminUser();

        $this->actingAs($admin)->get('/admin/errors')->assertOk()->assertSee('Nothing is broken right now');
        $this->actingAs($admin)->get('/admin/errors?source=api&new=1')->assertOk()->assertSee('No issue matches');
    });

    it('marks the filters that are on', function () {
        $admin = errorsAdminUser();

        $html = $this->actingAs($admin)->get('/admin/errors?source=api&status=resolved&new=1')->getContent();

        expect($html)->toMatch('/<a[^>]*aria-current="true"[^>]*>\s*API\s*<\/a>/')
            ->toMatch('/<a[^>]*aria-current="true"[^>]*>\s*Resolved\s*<\/a>/')
            ->toMatch('/<a[^>]*aria-current="true"[^>]*>\s*New in 24 hours\s*<\/a>/');
    });
});

describe('the background noise card', function () {
    it('is collapsed by default, a details element and no script', function () {
        $admin = errorsAdminUser();

        $html = $this->actingAs($admin)->get('/admin/errors')->assertOk()->getContent();

        expect($html)->toMatch('/<details(?![^>]*\sopen)[^>]*class="[^"]*err-noise/')
            ->toContain('Background noise (bots and browsers)');
    });

    it('shows the last 7 days of scanner counts and the dropped count', function () {
        $admin = errorsAdminUser();
        DB::table('error_noise_days')->insert([
            ['day' => '2026-11-04', 'kind' => 'scanner', 'count' => 4321],
            ['day' => '2026-11-04', 'kind' => 'dropped', 'count' => 12],
            ['day' => '2026-11-01', 'kind' => 'scanner', 'count' => 77],
            ['day' => '2026-11-01', 'kind' => 'dropped', 'count' => 5],
            ['day' => '2026-10-20', 'kind' => 'scanner', 'count' => 9999],
        ]);

        $html = $this->actingAs($admin)->get('/admin/errors')->getContent();
        preg_match('/<details[^>]*err-noise.*?<\/details>/s', $html, $card);

        expect($card[0])->toContain('4,321')->toContain('77')->toContain('12')->toContain('4 Nov 2026')->toContain('29 Oct 2026')
            ->not->toContain('9,999') // 20 October is outside the week
            ->toContain('4,398')->toContain('17'); // the week's totals: 4,321 + 77 scanner, 12 + 5 dropped
        expect(substr_count($card[0], '<tr'))->toBe(9); // a head row, seven days and the week's totals
    });

    it('is there even when nothing was counted', function () {
        $admin = errorsAdminUser();

        $this->actingAs($admin)->get('/admin/errors')->assertOk()->assertSee('Background noise (bots and browsers)');
    });
});

describe('the detail', function () {
    it('shows the title, the counts, the people, the versions and the trace in a pre', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([
            'title' => 'TypeError: format() on null', 'occurrences' => 4821, 'people' => 37,
            'first_seen_at' => CarbonImmutable::parse('2026-10-30 08:15:00', 'UTC'), 'first_version' => '1.27.0',
            'last_seen_at' => CarbonImmutable::parse('2026-11-04 09:40:00', 'UTC'), 'last_version' => '1.28.0',
        ]);
        errorsAdminSample($issue, ['trace' => "#0 app/Reports/Statement.php(88): format()\n#1 app/Http/Controllers/StatementController.php(20): build()"]);

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->getContent();

        expect($html)->toContain('TypeError: format() on null')->toContain('4,821')->toContain('37')
            ->toContain('30 Oct 2026')->toContain('1.27.0')->toContain('4 Nov 2026')->toContain('1.28.0')
            ->toMatch('/<pre[^>]*>[^<]*app\/Reports\/Statement\.php\(88\): format\(\)/');
    });

    it('shows 500+ when the people count reached its cap', function () {
        $admin = errorsAdminUser();
        $many = errorsAdminIssue(['people' => 500]);
        $some = errorsAdminIssue(['people' => 499]);

        expect($many->peopleLabel())->toBe('500+')->and($some->peopleLabel())->toBe('499')
            ->and(errorsAdminIssue(['people' => 0])->peopleLabel())->toBe('0');
        $this->actingAs($admin)->get('/admin/errors/'.$many->id)->assertSee('500+');
        $this->actingAs($admin)->get('/admin/errors/'.$some->id)->assertSee('499')->assertDontSee('500+');
    });

    it('shows up to five samples, newest first', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        foreach (range(1, 7) as $n) {
            errorsAdminSample($issue, ['message' => "Sighting number {$n} of seven", 'occurred_at' => now()->subHours(8 - $n)]);
        }

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->getContent();

        expect(substr_count($html, 'class="err-sample"'))->toBe(5)
            ->and($html)->toContain('Sighting number 7 of seven')->toContain('Sighting number 3 of seven')
            ->not->toContain('Sighting number 2 of seven');
        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertSeeInOrder(['Sighting number 7 of seven', 'Sighting number 6 of seven', 'Sighting number 3 of seven']);
    });

    it('describes a sighting with where, the answer, the version and the kind of device', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['source' => 'site', 'kind' => 'script']);
        errorsAdminSample($issue, ['status' => null, 'method' => null, 'route' => '/app/contracts/{id}', 'platform' => 'web', 'device' => 'tablet', 'version' => '1.28.0']);

        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()
            ->assertSee('/app/contracts/{id}')->assertSee('Tablet')->assertSee('Website')->assertSee('1.28.0');
    });

    it('says when a sighting has no trace instead of showing an empty box', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['kind' => 'http']);
        errorsAdminSample($issue, ['trace' => '', 'message' => '']);

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->getContent();

        expect($html)->toContain('No trace was kept for this one.')->not->toContain('<pre');
    });

    it('draws the 30-day timeline from the daily counts', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([], ['2026-11-04' => 9, '2026-10-07' => 2, '2026-10-05' => 40]); // the 5th is a day too early

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->getContent();
        preg_match('/<svg[^>]*class="err-days[^>]*>.*?<\/svg>/s', $html, $svg);

        expect($svg[0])->toContain('Last 30 days, oldest first: '.implode(', ', [0, 2, ...array_fill(0, 27, 0), 9]))
            ->and(substr_count($svg[0], '<rect'))->toBe(30);
    });

    it('labels what a browser or a phone reported, and nothing else', function () {
        $admin = errorsAdminUser();
        $script = errorsAdminIssue(['source' => 'site', 'kind' => 'script']);
        $crash = errorsAdminIssue(['source' => 'app', 'kind' => 'crash']);
        $server = errorsAdminIssue(['source' => 'site', 'kind' => 'exception']);

        $this->actingAs($admin)->get('/admin/errors/'.$script->id)->assertSee('Reported by a browser')->assertDontSee('Reported by a phone');
        $this->actingAs($admin)->get('/admin/errors/'.$crash->id)->assertSee('Reported by a phone')->assertDontSee('Reported by a browser');
        $this->actingAs($admin)->get('/admin/errors/'.$server->id)->assertDontSee('Reported by a phone')->assertDontSee('Reported by a browser');
    });

    it('says who resolved or ignored it, and when', function () {
        $admin = errorsAdminUser();
        $worker = User::factory()->platformAdmin('super_admin')->create(['name' => 'Layla Haddad']);
        $resolved = errorsAdminIssue(['status' => 'resolved', 'resolved_at' => CarbonImmutable::parse('2026-11-03 14:05:00', 'UTC'), 'resolved_by' => $worker->id]);
        $ignored = errorsAdminIssue(['status' => 'ignored', 'ignored_at' => CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'), 'ignored_by' => $admin->id]);
        $gone = errorsAdminIssue(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => (string) Str::uuid()]);

        $this->actingAs($admin)->get('/admin/errors/'.$resolved->id)->assertSee('Resolved by Layla Haddad on 3 Nov 2026');
        $this->actingAs($admin)->get('/admin/errors/'.$ignored->id)->assertSee('Ignored by '.$admin->name.' on 2 Nov 2026');
        $this->actingAs($admin)->get('/admin/errors/'.$gone->id)->assertSee('Someone no longer on the team');
    });

    it('says when and in which version it came back', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['reopened_at' => CarbonImmutable::parse('2026-11-03 11:00:00', 'UTC'), 'reopened_version' => '1.30.0']);

        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertSee('Came back in 1.30.0 on 3 Nov 2026');
    });

    it('offers Resolve, Ignore and Reopen as buttons in a form that PUTs the new status', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->getContent();

        expect($html)->toContain('action="'.route('admin.errors.status', $issue).'"')
            ->toContain('name="_method" value="PUT"')
            ->toMatch('/name="status" value="resolved"/')->toMatch('/name="status" value="ignored"/')->toMatch('/name="status" value="open"/')
            ->toMatch('/<button[^>]*>\s*Resolve\s*<\/button>/')->toMatch('/<button[^>]*>\s*Ignore\s*<\/button>/')->toMatch('/<button[^>]*>\s*Reopen\s*<\/button>/');
    });

    it('turns off the button for the status the issue already has', function (string $status, string $disabled) {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['status' => $status]);

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->getContent();

        expect($html)->toMatch('/<button[^>]*value="'.$disabled.'"[^>]*disabled|<button[^>]*disabled[^>]*value="'.$disabled.'"/');
        expect(preg_match_all('/<button[^>]*\bdisabled\b/', $html))->toBe(1);
    })->with([['open', 'open'], ['resolved', 'resolved'], ['ignored', 'ignored']]);

    it('links back to the list', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertSee('href="'.route('admin.errors.index').'"', false)->assertSee('All errors');
    });
});

describe('what is stored is still escaped', function () {
    it('shows markup in every stored field as text, in the list and the detail', function () {
        $admin = errorsAdminUser();
        $markup = '<script>alert(1)</script>';
        $handler = '<img src=x onerror=alert(1)>';
        $issue = errorsAdminIssue([
            'title' => "TypeError: {$markup}", 'class' => $markup, 'first_version' => $markup, 'last_version' => $handler,
            'reopened_at' => now()->subDay(), 'reopened_version' => $handler,
        ]);
        errorsAdminSample($issue, [
            'message' => "bad {$markup} and {$handler}",
            'trace' => "#0 app/X.php(1): {$markup}\n#1 {$handler}",
            'route' => "/x/{$markup}/{$handler}",
            'version' => $handler,
            'method' => '<b>',
        ]);

        foreach (['/admin/errors', '/admin/errors/'.$issue->id] as $path) {
            $page = $this->actingAs($admin)->get($path)->assertOk();

            $page->assertDontSee($markup, false)->assertDontSee($handler, false)->assertDontSee('<img', false)->assertDontSee('<script>alert', false)
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        }
    });

    it('never builds a link from a stored route or message', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['title' => 'javascript:alert(1)']);
        errorsAdminSample($issue, ['route' => 'javascript:alert(1)', 'message' => 'https://evil.example/phish']);

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->getContent();
        preg_match_all('/href="([^"]*)"/', $html, $links);

        expect(implode(' ', $links[1]))->not->toContain('javascript:')->not->toContain('evil.example');
    });

    it('does not use the unescaped output tag anywhere in the error views', function () {
        foreach (glob(resource_path('views/admin/errors/*.blade.php')) as $view) {
            expect(file_get_contents($view))->not->toContain('{!!');
        }
    });
});

describe('changing the status', function () {
    it('resolves an issue, records who and when, and writes the audit row', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['occurrences' => 12]);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])
            ->assertRedirect(route('admin.errors.show', $issue))->assertSessionHas('status');

        $issue->refresh();
        $audit = AuditLog::query()->where('action', 'error.resolved')->sole();

        expect($issue->status)->toBe('resolved')->and($issue->resolved_by)->toBe($admin->id)
            ->and($issue->resolved_at->equalTo(now()))->toBeTrue()
            ->and($issue->ignored_at)->toBeNull()->and($issue->ignored_by)->toBeNull()
            ->and($issue->occurrences)->toBe(12)
            ->and($audit->user_id)->toBe($admin->id)->and($audit->changes)->toBe(['issue' => $issue->id, 'from' => 'open'])
            ->and($audit->subject_id)->toBe($issue->id)->and($audit->tenant_id)->toBeNull();
    });

    it('ignores an issue and writes the audit row', function () {
        $admin = errorsAdminUser('super_admin');
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'ignored'])->assertRedirect();

        $issue->refresh();
        $audit = AuditLog::query()->where('action', 'error.ignored')->sole();

        expect($issue->status)->toBe('ignored')->and($issue->ignored_by)->toBe($admin->id)->and($issue->ignored_at->equalTo(now()))->toBeTrue()
            ->and($issue->resolved_at)->toBeNull()
            ->and($audit->user_id)->toBe($admin->id)->and($audit->changes)->toBe(['issue' => $issue->id, 'from' => 'open']);
    });

    it('reopens a resolved or ignored issue, clears who dealt with it, and leaves the count alone', function (string $from) {
        $admin = errorsAdminUser();
        $someone = errorsAdminUser();
        $issue = errorsAdminIssue(['status' => $from, $from.'_at' => now()->subDay(), $from.'_by' => $someone->id, 'occurrences' => 99]);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'open'])->assertRedirect();

        $issue->refresh();
        $audit = AuditLog::query()->where('action', 'error.reopened')->sole();

        expect($issue->status)->toBe('open')
            ->and($issue->resolved_at)->toBeNull()->and($issue->resolved_by)->toBeNull()
            ->and($issue->ignored_at)->toBeNull()->and($issue->ignored_by)->toBeNull()
            ->and($issue->occurrences)->toBe(99)
            ->and($audit->user_id)->toBe($admin->id)->and($audit->changes)->toBe(['issue' => $issue->id, 'from' => $from]);
    })->with(['resolved', 'ignored']);

    it('moves straight from resolved to ignored and back, keeping only the latest decision', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['status' => 'resolved', 'resolved_at' => now()->subDay(), 'resolved_by' => $admin->id]);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'ignored'])->assertRedirect();
        $issue->refresh();
        expect($issue->status)->toBe('ignored')->and($issue->resolved_at)->toBeNull()->and($issue->resolved_by)->toBeNull()->and($issue->ignored_by)->toBe($admin->id);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertRedirect();
        $issue->refresh();
        expect($issue->status)->toBe('resolved')->and($issue->ignored_at)->toBeNull()->and($issue->ignored_by)->toBeNull()->and($issue->resolved_by)->toBe($admin->id);

        expect(AuditLog::query()->where('action', 'error.ignored')->sole()->changes)->toBe(['issue' => $issue->id, 'from' => 'resolved'])
            ->and(AuditLog::query()->where('action', 'error.resolved')->sole()->changes)->toBe(['issue' => $issue->id, 'from' => 'ignored']);
    });

    it('writes one audit row per change and none for a button pressed twice', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved']);
        $first = $issue->fresh()->resolved_at;
        $this->travelTo(now()->addMinutes(5));
        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertRedirect(route('admin.errors.show', $issue))->assertSessionHas('status', 'Already resolved.');

        expect(AuditLog::query()->where('action', 'like', 'error.%')->count())->toBe(1)
            ->and($issue->fresh()->resolved_at->equalTo($first))->toBeTrue();
    });

    it('refuses a status it does not know, with a 422, and changes nothing', function (mixed $status) {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => $status])->assertStatus(422);
        $this->actingAs($admin)->putJson('/admin/errors/'.$issue->id.'/status', ['status' => $status])->assertStatus(422);

        expect($issue->fresh()->status)->toBe('open')->and(AuditLog::query()->where('action', 'like', 'error.%')->count())->toBe(0);
    })->with(['banana', 'RESOLVED', 'resolve', '', [['resolved']]]); // the last is a list, sent as status[]

    it('refuses a request with no status', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status')->assertStatus(422);
        expect($issue->fresh()->status)->toBe('open');
    });

    it('cannot be used to change anything but the status', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['occurrences' => 5, 'people' => 2, 'title' => 'Original title']);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved', 'occurrences' => 0, 'people' => 999, 'title' => 'Hacked', 'resolved_by' => 'someone-else']);

        $issue->refresh();
        expect($issue->occurrences)->toBe(5)->and($issue->people)->toBe(2)->and($issue->title)->toBe('Original title')->and($issue->resolved_by)->toBe($admin->id);
    });

    it('is throttled to 30 changes a minute', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        $statuses = ['resolved', 'ignored', 'open'];

        $codes = collect(range(0, 30))->map(fn (int $n) => $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => $statuses[$n % 3]])->getStatusCode());

        expect($codes->take(30)->unique()->all())->toBe([302])->and($codes->last())->toBe(429);
    });

    it('is the job of ErrorTriage: resolve, ignore and reopen return the issue and audit the actor', function () {
        $admin = errorsAdminUser();
        $this->actingAs($admin);
        $issue = errorsAdminIssue();
        $triage = new ErrorTriage;

        $resolved = $triage->resolve($issue, $admin);
        expect($resolved)->toBeInstanceOf(ErrorIssue::class)->and($resolved->status)->toBe('resolved')->and($resolved->resolved_by)->toBe($admin->id);

        $ignored = $triage->ignore($issue->fresh(), $admin);
        expect($ignored->status)->toBe('ignored')->and($ignored->ignored_by)->toBe($admin->id)->and($ignored->resolved_by)->toBeNull();

        $reopened = $triage->reopen($issue->fresh(), $admin);
        expect($reopened->status)->toBe('open')->and($reopened->ignored_by)->toBeNull();

        expect(AuditLog::query()->orderBy('created_at')->orderBy('id')->pluck('action')->sort()->values()->all())
            ->toBe(['error.ignored', 'error.reopened', 'error.resolved']);
    });

    it('does not write over the counters the recorder keeps', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['occurrences' => 10]);
        $stale = ErrorIssue::query()->find($issue->id);

        // The recorder counts a sighting after the page was loaded and before the button is pressed.
        DB::table('error_issues')->where('id', $issue->id)->update(['occurrences' => 11, 'last_seen_at' => now()]);
        (new ErrorTriage)->resolve($stale, $admin);

        expect(DB::table('error_issues')->where('id', $issue->id)->value('occurrences'))->toBe(11);
    });
});

describe('the menu and the admin home', function () {
    it('has an Errors entry in the menu, on the admin pages, that is marked when it is open', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $home = errorsAdminRail($this->actingAs($admin)->get('/admin')->getContent());
        $list = errorsAdminRail($this->actingAs($admin)->get('/admin/errors')->getContent());
        $detail = errorsAdminRail($this->actingAs($admin)->get('/admin/errors/'.$issue->id)->getContent());

        expect($home)->toContain('href="'.route('admin.errors.index').'"')->not->toContain('href="'.route('admin.errors.index').'" aria-current="page"')
            ->and($list)->toMatch('/href="'.preg_quote(route('admin.errors.index'), '/').'"[^>]*aria-current="page"/')
            ->and($detail)->toMatch('/href="'.preg_quote(route('admin.errors.index'), '/').'"[^>]*aria-current="page"/')
            ->and(substr_count($list, 'aria-current="page"'))->toBe(1);
    });

    it('shows the number of open issues in a badge, and no badge at zero', function () {
        $admin = errorsAdminUser();

        expect(errorsAdminRail($this->actingAs($admin)->get('/admin')->getContent()))->not->toContain('rail-count');

        errorsAdminIssue();
        errorsAdminIssue();
        errorsAdminIssue(['status' => 'resolved', 'resolved_at' => now()]);
        errorsAdminIssue(['status' => 'ignored', 'ignored_at' => now()]);

        $rail = errorsAdminRail($this->actingAs($admin)->get('/admin')->getContent());

        expect($rail)->toMatch('/class="rail-count"[^>]*>\s*<span aria-hidden="true">2<\/span>\s*<span class="sr-only">2 open<\/span>/');
    });

    it('caps a long badge at 99+', function () {
        $admin = errorsAdminUser();
        DB::table('error_issues')->insert(collect(range(1, 120))->map(fn (int $n) => [
            'id' => (string) Str::uuid(), 'fingerprint' => sha1("bulk {$n}"), 'source' => 'site', 'kind' => 'exception', 'title' => "Bulk {$n}",
            'status' => 'open', 'occurrences' => 1, 'people' => 0, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])->all());

        $rail = errorsAdminRail($this->actingAs($admin)->get('/admin')->getContent());

        expect($rail)->toContain('<span aria-hidden="true">99+</span>')->toContain('120 open');
    });

    it('still opens every admin page when the open issues cannot be counted', function () {
        $admin = errorsAdminUser();
        Schema::disableForeignKeyConstraints();
        Schema::drop('error_occurrences');
        Schema::drop('error_issue_days');
        Schema::drop('error_issue_people');
        Schema::drop('error_issues');

        $rail = errorsAdminRail($this->actingAs($admin)->get('/admin/businesses')->assertOk()->getContent());

        expect($rail)->toContain('href="'.route('admin.errors.index').'"')->not->toContain('rail-count');
    });

    it('counts the open issues with a single cheap query', function () {
        errorsAdminIssue();
        errorsAdminIssue(['status' => 'resolved', 'resolved_at' => now()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $count = ErrorIssue::openCount();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($count)->toBe(1)->and($queries)->toHaveCount(1)->and($queries[0]['query'])->toContain('count(*)')->toContain('"status"');
    });

    it('adds a check to what is set up: fine when no server error was seen in the last 24 hours', function () {
        errorsAdminIssue(['last_seen_at' => now()->subHours(25)]); // an exception, but a day ago
        errorsAdminIssue(['last_seen_at' => now()->subMinutes(5), 'status' => 'resolved', 'resolved_at' => now()]);
        errorsAdminIssue(['last_seen_at' => now()->subMinutes(5), 'status' => 'ignored', 'ignored_at' => now()]);

        $check = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'errors');

        expect($check['status'])->toBe('ok')->and($check['title'])->toBe('Errors in the last 24 hours')->and($check['detail'])->toBe('No server errors in the last 24 hours.');
    });

    it('does not turn yellow for the quiet kinds: a broken link, an expired form, a slow query, a browser or a phone', function () {
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.404', 'title' => 'Error 404 on /old-link']);
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.419', 'title' => 'Error 419 on /login']);
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.429', 'title' => 'Error 429 on /login']);
        errorsAdminIssue(['source' => 'database', 'kind' => 'slow_query']);
        errorsAdminIssue(['source' => 'site', 'kind' => 'script']);
        errorsAdminIssue(['source' => 'app', 'kind' => 'crash']);

        $check = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'errors');

        expect($check['status'])->toBe('ok')->and($check['detail'])->toBe('No server errors in the last 24 hours.');
    });

    it('warns, in words, how many server errors were seen in the last 24 hours', function () {
        errorsAdminIssue(['last_seen_at' => now()->subHours(23)]); // an open exception
        $one = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'errors');
        errorsAdminIssue(['source' => 'api', 'kind' => 'http', 'class' => 'http.503', 'last_seen_at' => now()->subMinutes(5)]);
        errorsAdminIssue(['source' => 'database', 'kind' => 'exception', 'class' => 'PDOException', 'last_seen_at' => now()->subHour()]);
        errorsAdminIssue(['source' => 'site', 'kind' => 'http', 'class' => 'http.500', 'last_seen_at' => now()->subHour()]);
        errorsAdminIssue(['last_seen_at' => now()->subDays(3)]); // too old
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.404']); // quiet
        $four = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'errors');

        expect($one['status'])->toBe('warn')->and($one['detail'])->toBe('1 server error seen in the last 24 hours')
            ->and($four['status'])->toBe('warn')->and($four['detail'])->toBe('4 server errors seen in the last 24 hours');
    });

    it('counts the server errors with one query on the issues table', function () {
        errorsAdminIssue();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PlatformOverview::class)->health();
        $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_contains($query, 'error_issues'))->values()->all();
        DB::disableQueryLog();

        expect($sql)->toHaveCount(1)->and($sql[0])->toContain('count(*)');
    });

    it('says so on the home line when error recording is switched off, instead of "No server errors"', function () {
        config(['qistas.errors.enabled' => false]);

        $check = collect(app(PlatformOverview::class)->health())->firstWhere('key', 'errors');

        expect($check['status'])->toBe('warn')
            ->and($check['detail'])->toBe('Error recording is switched off (QISTAS_ERRORS). Nothing new is being recorded.')
            ->and($check['detail'])->not->toContain('No server errors');
    });

    it('shows the line on the admin home with a way to the list', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue();
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.502']);
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.404']);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Errors in the last 24 hours')->assertSee('2 server errors seen in the last 24 hours')
            ->assertSee('href="'.route('admin.errors.index').'"', false);
    });

    it('shows the good line on a quiet day, while the menu badge still counts every open issue', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['kind' => 'http', 'class' => 'http.404']);

        $page = $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Errors in the last 24 hours')->assertSee('No server errors in the last 24 hours.');

        expect(errorsAdminRail($page->getContent()))->toContain('<span class="sr-only">1 open</span>');
    });
});

describe('an honest message after a press', function () {
    it('says Already ... and writes nothing when the issue is already in the status that was asked for', function (string $status, string $message) {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue($status === 'open' ? [] : ['status' => $status, $status.'_at' => now()->subDay(), $status.'_by' => $admin->id]);
        $before = $issue->fresh();

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => $status])
            ->assertRedirect(route('admin.errors.show', $issue))->assertSessionHas('status', $message);

        expect(AuditLog::query()->where('action', 'like', 'error.%')->count())->toBe(0)
            ->and($issue->fresh()->updated_at->equalTo($before->updated_at))->toBeTrue();
    })->with([['resolved', 'Already resolved.'], ['ignored', 'Already ignored.'], ['open', 'Already open.']]);

    it('says what happened when something did', function (string $status, string $message) {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue($status === 'open' ? ['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $admin->id] : []);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => $status])->assertSessionHas('status', $message);
    })->with([['resolved', 'Marked as resolved.'], ['ignored', 'Ignored. New sightings of it are no longer counted.'], ['open', 'Reopened.']]);

    it('does not claim a change a stale page asked for, when someone else made it first', function () {
        $admin = errorsAdminUser();
        $other = errorsAdminUser('super_admin');
        $issue = errorsAdminIssue();

        // This page was drawn while the issue was open; a colleague resolves it before the button is pressed.
        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk();
        (new ErrorTriage)->resolve($issue->fresh(), $other);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertSessionHas('status', 'Already resolved.');

        expect(AuditLog::query()->where('action', 'error.resolved')->count())->toBe(1)
            ->and($issue->fresh()->resolved_by)->toBe($other->id);
    });

    it('still makes a different change on a stale page, and audits where it really came from', function () {
        $admin = errorsAdminUser();
        $other = errorsAdminUser('super_admin');
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk();
        (new ErrorTriage)->resolve($issue->fresh(), $other);

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'ignored'])
            ->assertSessionHas('status', 'Ignored. New sightings of it are no longer counted.');

        expect(AuditLog::query()->where('action', 'error.ignored')->sole()->changes)->toBe(['issue' => $issue->id, 'from' => 'resolved']);
    });
});

/** One sighting of the same failure, a minute after the last. */
function errorsAdminSight(ErrorRecorder $recorder, ?string $version = '1.30.0'): Recorded
{
    test()->travelTo(now()->addMinute());

    return $recorder->record(new ErrorEvent(source: 'site', kind: 'exception', class: 'RuntimeException', message: 'Boom', version: $version));
}

describe('came back means: reopened by the recorder after the last staff decision', function () {
    it('is cleared when staff resolve, kept through a staff reopen, and set again by a new sighting', function () {
        $admin = errorsAdminUser();
        $recorder = app(ErrorRecorder::class);
        $triage = new ErrorTriage;

        expect(errorsAdminSight($recorder))->toBe(Recorded::Created);
        $issue = ErrorIssue::query()->sole();

        $triage->resolve($issue->fresh(), $admin);
        expect(errorsAdminSight($recorder))->toBe(Recorded::Reopened);
        $issue->refresh();
        expect($issue->hasComeBack())->toBeTrue()->and($issue->reopened_version)->toBe('1.30.0');

        test()->travelTo(now()->addMinute());
        $triage->resolve($issue->fresh(), $admin);
        $issue->refresh();
        expect($issue->reopened_at)->toBeNull()->and($issue->reopened_version)->toBeNull()->and($issue->hasComeBack())->toBeFalse();

        // A staff reopen is a decision, not a recurrence.
        test()->travelTo(now()->addMinute());
        $triage->reopen($issue->fresh(), $admin);
        $issue->refresh();
        expect($issue->status)->toBe('open')->and($issue->reopened_at)->toBeNull()->and($issue->hasComeBack())->toBeFalse();

        // Resolved again, and then it really does happen again.
        test()->travelTo(now()->addMinute());
        $triage->resolve($issue->fresh(), $admin);
        expect(errorsAdminSight($recorder, '1.31.0'))->toBe(Recorded::Reopened);
        $issue->refresh();
        expect($issue->hasComeBack())->toBeTrue()->and($issue->reopened_version)->toBe('1.31.0');
    });

    it('is cleared by a staff ignore too', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['reopened_at' => now()->subDay(), 'reopened_version' => '1.29.0']);

        (new ErrorTriage)->ignore($issue, $admin);
        $issue->refresh();

        expect($issue->status)->toBe('ignored')->and($issue->reopened_at)->toBeNull()->and($issue->reopened_version)->toBeNull();
    });

    it('is left alone by a staff reopen', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $admin->id, 'reopened_at' => now()->subHours(2), 'reopened_version' => '1.29.0']);

        (new ErrorTriage)->reopen($issue, $admin);

        expect($issue->fresh()->reopened_version)->toBe('1.29.0');
    });
});

describe('the background noise card does not hide what was dropped', function () {
    it('says in the closed summary how many were dropped in the last 7 days', function () {
        $admin = errorsAdminUser();
        DB::table('error_noise_days')->insert([
            ['day' => '2026-11-04', 'kind' => 'dropped', 'count' => 12],
            ['day' => '2026-11-02', 'kind' => 'dropped', 'count' => 5],
            ['day' => '2026-10-20', 'kind' => 'dropped', 'count' => 99],
        ]);

        $html = $this->actingAs($admin)->get('/admin/errors')->getContent();
        preg_match('/<details[^>]*err-noise[^>]*>\s*<summary.*?<\/summary>/s', $html, $summary);

        expect($summary[0])->toContain('17 dropped')->not->toContain('99');
    });

    it('leaves the dropped note out when nothing was dropped', function () {
        $admin = errorsAdminUser();
        DB::table('error_noise_days')->insert(['day' => '2026-11-04', 'kind' => 'scanner', 'count' => 40]);

        $html = $this->actingAs($admin)->get('/admin/errors')->getContent();
        preg_match('/<details[^>]*err-noise[^>]*>\s*<summary.*?<\/summary>/s', $html, $summary);

        expect($summary[0])->not->toContain('dropped')->toContain('Last 7 days: 40');
    });
});

describe('a switched-off recorder is said out loud', function () {
    it('shows a notice at the top of the list', function () {
        $admin = errorsAdminUser();

        $this->actingAs($admin)->get('/admin/errors')->assertDontSee('Error recording is switched off');

        config(['qistas.errors.enabled' => false]);
        $this->actingAs($admin)->get('/admin/errors')->assertOk()
            ->assertSee('Error recording is switched off (QISTAS_ERRORS). Nothing new is being recorded.');
    });
});

describe('the back link keeps the list filters', function () {
    it('uses a back value that points at the list, escaped', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        $back = '/admin/errors?source=api&status=all&new=1&page=2';

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id.'?'.http_build_query(['back' => $back]))->assertOk()->getContent();

        expect($html)->toContain('href="'.e(url($back)).'"')->toContain('&amp;status=all');
    });

    it('ignores a back value that points anywhere else, or carries anything odd', function (string $back) {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id.'?'.http_build_query(['back' => $back]))->assertOk()->getContent();

        // (The language links on the page repeat the address of the page itself, percent-encoded, so the check is on the
        // back link and on the status form, the two places the value could be used.)
        expect($html)->toContain('<a class="err-back" href="'.route('admin.errors.index').'">')
            ->not->toContain('name="back"')->not->toContain('<b>')->not->toContain('javascript:');
    })->with([
        '//evil.example', 'https://evil.example/admin/errors', '/app', '/admin/errors/../../app', '/admin/errorsx', '/admin/errors/00000000-0000-0000-0000-000000000000',
        '/admin/errors?a="><b>', "/admin/errors?a=1\nb=2", '/admin/errors#x', 'javascript:alert(1)', '\\\\evil.example', '/admin/errors?a=<b>',
    ]);

    it('is added to a row link of a filtered list, and left off the plain list', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['source' => 'api', 'first_seen_at' => now()->subHour()]);

        $filtered = errorsAdminRow($this->actingAs($admin)->get('/admin/errors?source=api&new=1')->getContent(), $issue);
        $plain = errorsAdminRow($this->actingAs($admin)->get('/admin/errors')->getContent(), $issue);

        expect($filtered)->toContain('href="'.route('admin.errors.show', ['issue' => $issue, 'back' => '/admin/errors?source=api&new=1']).'"')
            ->and($plain)->toContain('href="'.route('admin.errors.show', $issue).'"');
    });

    it('remembers the page of the list too', function () {
        $admin = errorsAdminUser();
        foreach (range(1, 30) as $n) {
            errorsAdminIssue(['last_seen_at' => now()->subMinutes($n)]);
        }
        $onPageTwo = ErrorIssue::query()->orderByDesc('last_seen_at')->orderByDesc('id')->skip(25)->first();

        $row = errorsAdminRow($this->actingAs($admin)->get('/admin/errors?page=2')->getContent(), $onPageTwo);

        expect($row)->toContain(e(route('admin.errors.show', ['issue' => $onPageTwo, 'back' => '/admin/errors?page=2'])));
    });

    it('is carried through a status change, and a bad one is dropped there too', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        $back = '/admin/errors?source=api';

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved', 'back' => $back])
            ->assertRedirect(route('admin.errors.show', ['issue' => $issue, 'back' => $back]));
        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'open', 'back' => 'https://evil.example'])
            ->assertRedirect(route('admin.errors.show', $issue));

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id.'?'.http_build_query(['back' => $back]))->getContent();
        expect($html)->toContain('<input type="hidden" name="back" value="'.e($back).'">');
    });
});

describe('the detail says the same things the list does', function () {
    it('shows the growing mark, and the legend of the three marks', function () {
        $admin = errorsAdminUser();
        $growing = errorsAdminIssue([], ['2026-11-04' => 6, '2026-11-02' => 1]);
        $steady = errorsAdminIssue([], ['2026-11-04' => 6, '2026-11-03' => 5, '2026-11-02' => 5, '2026-11-01' => 5, '2026-10-31' => 5, '2026-10-30' => 5, '2026-10-29' => 5, '2026-10-28' => 5]);
        $resolved = errorsAdminIssue(['status' => 'resolved', 'resolved_at' => now()], ['2026-11-04' => 6]);

        $page = $this->actingAs($admin)->get('/admin/errors/'.$growing->id)->assertOk();
        $page->assertSee('data-mark="growing"', false)->assertSee('New: first seen in the last 24 hours. Came back:')->assertSee('Growing: today at least 3 times');

        $this->actingAs($admin)->get('/admin/errors/'.$steady->id)->assertDontSee('data-mark="growing"', false);
        $this->actingAs($admin)->get('/admin/errors/'.$resolved->id)->assertDontSee('data-mark="growing"', false);
    });
});

describe('times', function () {
    it('are time elements with the exact UTC time as a tooltip, and the list says how long ago it was last seen', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([
            'first_seen_at' => CarbonImmutable::parse('2026-10-30 08:15:00', 'UTC'),
            'last_seen_at' => CarbonImmutable::parse('2026-11-04 09:40:00', 'UTC'),
        ]);

        $row = errorsAdminRow($this->actingAs($admin)->get('/admin/errors')->getContent(), $issue);

        expect($row)->toContain('<time datetime="2026-11-04T09:40:00+00:00" title="2026-11-04 09:40 UTC">4 Nov 2026, 09:40</time>')
            ->toContain('<time datetime="2026-10-30T08:15:00+00:00" title="2026-10-30 08:15 UTC">30 Oct 2026, 08:15</time>')
            ->toContain('50 minutes ago')->not->toContain('5 days ago');
    });

    it('say how long ago on the detail page for first and last seen, and give a sighting its exact time', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([
            'first_seen_at' => CarbonImmutable::parse('2026-10-30 08:15:00', 'UTC'),
            'last_seen_at' => CarbonImmutable::parse('2026-11-04 09:40:00', 'UTC'),
        ]);
        errorsAdminSample($issue, ['occurred_at' => CarbonImmutable::parse('2026-11-04 09:40:00', 'UTC')]);

        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()
            ->assertSee('5 days ago')->assertSee('50 minutes ago')
            ->assertSee('<time datetime="2026-11-04T09:40:00+00:00" title="2026-11-04 09:40 UTC">', false);
    });

    it('are said in the language of the page', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['last_seen_at' => now()->subHours(3)]);

        $this->actingAs($admin)->get('/admin/errors?lang=ar')->assertOk()->assertSee('منذ');
    });
});

describe('Ignore asks for one more step, without script', function () {
    it('keeps the real button inside a closed details element, with the sentence that explains it', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->getContent();

        expect($html)->toMatch('/<details(?![^>]*\sopen)[^>]*class="[^"]*err-ignore[^"]*"[^>]*>\s*<summary[^>]*>\s*Ignore…\s*<\/summary>.*?New sightings will not be counted while this is ignored\. You can reopen it later\..*?<button[^>]*name="status"[^>]*value="ignored"[^>]*>\s*Ignore\s*<\/button>.*?<\/details>/s')
            ->and($html)->not->toMatch('/\sonsubmit=|\sonclick=|confirm\(/')
            ->and(preg_match_all('/<button[^>]*value="resolved"/', $html))->toBe(1);

        // Resolve and Reopen are not behind the extra step.
        preg_match('/<details[^>]*err-ignore.*?<\/details>/s', $html, $details);
        expect($details[0])->not->toContain('value="resolved"')->not->toContain('value="open"');
    });

    it('still posts status=ignored from that button', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'ignored'])->assertRedirect();

        expect($issue->fresh()->status)->toBe('ignored');
    });

    it('shows a plain disabled button instead, when it is ignored already', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['status' => 'ignored', 'ignored_at' => now(), 'ignored_by' => $admin->id]);

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->getContent();

        expect($html)->not->toContain('err-ignore')->toMatch('/<button[^>]*value="ignored"[^>]*disabled|<button[^>]*disabled[^>]*value="ignored"/');
    });
});

describe('the words in Arabic agree with the feminine issue', function () {
    it('uses the agreed forms', function () {
        app()->setLocale('ar');

        expect(__('Resolved'))->toBe('محلولة')->and(__('Ignored'))->toBe('متجاهَلة')->and(__('Dropped'))->toBe('المُهمَلة')
            ->and(__('Background noise (bots and browsers)'))->toBe('حركة الروبوتات والمتصفحات في الخلفية')
            ->and(__('Script error'))->toBe('خطأ في البرنامج النصي');
    });

    it('does not leave the old wording in the other sentences', function () {
        $ar = json_decode(file_get_contents(lang_path('app/ar.json')), true, flags: JSON_THROW_ON_ERROR);

        expect(implode(' ', $ar))->not->toContain('المُسقَطة')->not->toContain('الضجيج في الخلفية');
    });
});

describe('what the screens refuse, and what they survive', function () {
    it('gives a suspended staff member nothing: not the list, not the buttons', function () {
        $suspended = User::factory()->platformAdmin('admin')->suspended()->create();
        $issue = errorsAdminIssue();

        $this->actingAs($suspended)->get('/admin/errors')->assertRedirect(route('account.suspended'));
        $this->actingAs($suspended)->get('/admin/errors/'.$issue->id)->assertRedirect(route('account.suspended'));
        $this->actingAs($suspended)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertRedirect(route('account.suspended'));

        expect($issue->fresh()->status)->toBe('open')->and(AuditLog::query()->where('action', 'like', 'error.%')->count())->toBe(0);
    });

    it('refuses a status change without the forgery token, and takes it with one', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        // The framework skips the forgery check in a test run; a different environment name puts it back.
        app()->detectEnvironment(fn () => 'local');

        $this->actingAs($admin)->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved'])->assertStatus(419);
        expect($issue->fresh()->status)->toBe('open');

        $this->actingAs($admin)->withSession(['_token' => 'a-token'])->put('/admin/errors/'.$issue->id.'/status', ['status' => 'resolved', '_token' => 'a-token'])->assertRedirect();
        expect($issue->fresh()->status)->toBe('resolved');
    });

    it('shows a message with a right-to-left override and a NUL without breaking the page', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['title' => "Odd \u{202E}title\u{202C} here"]);
        errorsAdminSample($issue, ['message' => "before \u{202E}reversed\0after end", 'trace' => "#0 app/X.php(1): \u{202E}go\n#1 {main}"]);

        $list = $this->actingAs($admin)->get('/admin/errors')->assertOk()->getContent();
        $page = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk()->getContent();

        // Stored text sits in elements that isolate their direction, so an override cannot reach the text around them.
        expect($page)->toContain('<h1 class="display err-h1" dir="auto">')->toContain('<p class="err-message" dir="auto">before')
            ->toContain('<pre class="err-trace" dir="ltr"')
            ->and($list)->toContain('class="cell-link err-title" dir="auto"')
            ->and(substr_count($page, '<article'))->toBe(substr_count($page, '</article>'))
            ->and(substr_count($page, '<main'))->toBe(1);
    });
});

describe('small things', function () {
    it('gives each trace a name of its own for a screen reader', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        errorsAdminSample($issue, ['occurred_at' => now()->subHours(2)]);
        errorsAdminSample($issue, ['occurred_at' => now()->subHours(1)]);

        $html = $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->getContent();

        expect($html)->toContain('aria-label="Trace of sighting 1"')->toContain('aria-label="Trace of sighting 2"')->not->toContain('aria-label="Trace"');
    });

    it('has one rule for the noise table heading cells in the admin stylesheet', function () {
        $css = file_get_contents(resource_path('css/admin.css'));

        expect(substr_count($css, '.err-noise-body .table tbody th {'))->toBe(1);
    });
});

describe('in Arabic', function () {
    it('reads right to left, with the list, the filters and the noise card in Arabic', function () {
        $admin = errorsAdminUser();
        errorsAdminIssue(['source' => 'api']);

        $this->actingAs($admin)->get('/admin/errors?lang=ar')->assertOk()
            ->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false)
            ->assertSee('الأخطاء')->assertSee('حركة الروبوتات والمتصفحات في الخلفية')
            ->assertDontSee('Background noise')->assertDontSee('Times seen');
    });

    it('has the detail and the buttons in Arabic too', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue(['source' => 'app', 'kind' => 'crash']);
        errorsAdminSample($issue, ['platform' => 'android', 'device' => 'mobile', 'status' => null, 'method' => null]);

        $page = $this->actingAs($admin)->get('/admin/errors/'.$issue->id.'?lang=ar')->assertOk();

        $page->assertSee('dir="rtl"', false)->assertSee('أبلغ عنه هاتف')
            ->assertDontSee('Reported by a phone')->assertDontSee('Resolve')->assertDontSee('Latest sightings');
    });

    it('keeps the trace left to right, inside a right-to-left page', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue();
        errorsAdminSample($issue);

        $this->actingAs($admin)->get('/admin/errors/'.$issue->id.'?lang=ar')->assertSee('<pre class="err-trace" dir="ltr"', false);
    });
});

describe('speed', function () {
    it('asks the database the same questions for three issues as for a full page of them', function () {
        $admin = errorsAdminUser();
        foreach (range(1, 3) as $n) {
            errorsAdminIssue(['title' => "Few {$n}"], ['2026-11-04' => 2, '2026-11-02' => 1]);
        }
        $this->actingAs($admin)->get('/admin/errors')->assertOk(); // the first request warms what every request needs

        $few = errorsAdminQueries(fn () => $this->actingAs($admin)->get('/admin/errors')->assertOk());

        foreach (range(1, 40) as $n) {
            errorsAdminIssue(['title' => "Many {$n}", 'last_seen_at' => now()->subMinutes($n)], ['2026-11-04' => $n, '2026-11-02' => 1]);
        }
        $many = errorsAdminQueries(fn () => $this->actingAs($admin)->get('/admin/errors')->assertOk());

        // The signed-in user's workspace (twice, for the menu), the menu's count of open issues, the page's count and rows, the
        // days of all 25 rows at once and the week of noise: seven, however many rows there are.
        expect($many)->toBe($few)->and($many)->toBe(7);
    });

    it('stays at that number with hundreds of issues, on any page and with filters', function () {
        $admin = errorsAdminUser();
        $rows = [];
        foreach (range(1, 400) as $n) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'fingerprint' => sha1("many {$n}"), 'source' => ['site', 'api', 'database', 'app'][$n % 4],
                'kind' => 'exception', 'title' => "Issue {$n}", 'status' => $n % 10 === 0 ? 'resolved' : 'open', 'occurrences' => $n, 'people' => 0,
                'first_seen_at' => now()->subDays($n % 20), 'last_seen_at' => now()->subMinutes($n), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('error_issues')->insert($chunk);
        }
        DB::table('error_issue_days')->insert(array_map(fn (array $row) => ['issue_id' => $row['id'], 'day' => '2026-11-04', 'count' => 2], $rows));

        $this->actingAs($admin)->get('/admin/errors')->assertOk();
        $queries = [
            errorsAdminQueries(fn () => $this->actingAs($admin)->get('/admin/errors')->assertOk()),
            errorsAdminQueries(fn () => $this->actingAs($admin)->get('/admin/errors?page=3')->assertOk()),
            errorsAdminQueries(fn () => $this->actingAs($admin)->get('/admin/errors?status=all&source=api&new=1')->assertOk()),
        ];

        expect($queries)->toBe([7, 7, 7]);
        expect(errorsAdminRows($this->actingAs($admin)->get('/admin/errors')->getContent()))->toBe(25);
    });

    it('opens a detail page with a handful of queries whatever the history', function () {
        $admin = errorsAdminUser();
        $issue = errorsAdminIssue([], ['2026-11-04' => 3]);
        foreach (range(1, 5) as $n) {
            errorsAdminSample($issue, ['message' => "Sample {$n}"]);
        }
        $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk();

        $count = errorsAdminQueries(fn () => $this->actingAs($admin)->get('/admin/errors/'.$issue->id)->assertOk());

        // The workspace lookups for the menu, the issue, its samples, its 30 days and the menu's count of open issues: six.
        expect($count)->toBe(6);
    });
});
