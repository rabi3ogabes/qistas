<?php

use App\Console\Commands\ErrorsPruneCommand;
use App\Errors\ErrorAlerts;
use App\Errors\ErrorEvent;
use App\Errors\ErrorRecorder;
use App\Errors\Recorded;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| What the error center forgets, and when (design section 10): sample occurrences after 30 days; an open issue 90 days
| after it was last seen; a resolved issue 30 days after it was resolved; an ignored one 30 days after its last sighting;
| the scanner-noise counts after 30 days. An issue takes its days and its people with it. The two retention periods are
| settings read when the command runs, and the command refuses to run on a nonsense value, as the sign-in prune does.
|
| It also clears the rows the error center leaves in the cache table when the cache is kept in the database (production):
| nothing else reads them again once they have expired, so without this the table would only grow.
|
| Every date below is built from a frozen "now", so "exactly 30 days" is a real boundary and not a race.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30:00', 'UTC'));
    config(['qistas.errors.occurrence_days' => 30, 'qistas.errors.issue_days' => 90]);
});

/** An issue as the recorder writes one, with a day, a person and a sample of its own; $with replaces any column. */
function errorsPruneIssue(array $with = []): ErrorIssue
{
    $issue = ErrorIssue::query()->forceCreate($with + [
        'fingerprint' => sha1(Str::random(32)),
        'source' => 'site',
        'kind' => 'exception',
        'title' => 'an issue',
        'class' => 'RuntimeException',
        'status' => 'open',
        'occurrences' => 1,
        'people' => 1,
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now()->subHour(),
    ]);

    DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => $issue->last_seen_at->toDateString(), 'count' => 1]);
    DB::table('error_issue_people')->insert(['issue_id' => $issue->id, 'person' => substr(sha1($issue->id), 0, 16)]);
    errorsPruneSample($issue, $issue->last_seen_at);

    return $issue;
}

/** An open issue last seen $days days ago (and $hours hours more), titled by its age so a failure reads. */
function errorsPruneOpen(int $days, int $hours = 0): ErrorIssue
{
    return errorsPruneIssue(['title' => "open, seen {$days} days {$hours} hours ago", 'last_seen_at' => now()->subDays($days)->subHours($hours)]);
}

function errorsPruneSample(ErrorIssue $issue, CarbonInterface $at, string $message = 'Boom'): ErrorOccurrence
{
    return ErrorOccurrence::query()->create([
        'issue_id' => $issue->id, 'occurred_at' => $at, 'status' => 500, 'method' => 'GET', 'route' => '/app/x',
        'message' => $message, 'trace' => '', 'version' => '1.30.0', 'platform' => 'server', 'device' => null,
    ]);
}

/** The titles of the issues that are left, sorted. */
function errorsPruneLeft(): array
{
    return ErrorIssue::query()->pluck('title')->sort()->values()->all();
}

/** The messages of the sample occurrences that are left, sorted. */
function errorsPruneSamplesLeft(): array
{
    return ErrorOccurrence::query()->pluck('message')->sort()->values()->all();
}

function errorsPruneNoise(string $day, string $kind = 'scanner', int $count = 4): void
{
    DB::table('error_noise_days')->insert(['day' => $day, 'kind' => $kind, 'count' => $count]);
}

/** @return array{days: int, people: int, samples: int} what hangs on the issues */
function errorsPruneChildren(): array
{
    return [
        'days' => DB::table('error_issue_days')->count(),
        'people' => DB::table('error_issue_people')->count(),
        'samples' => DB::table('error_occurrences')->count(),
    ];
}

/** $count old issues straight into the table, in a few statements. */
function errorsPruneBulk(int $count, array $with): void
{
    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('error_issues')->insert(array_map(fn () => $with + [
            'id' => (string) Str::uuid7(),
            'fingerprint' => sha1(Str::random(32)),
            'source' => 'site',
            'kind' => 'exception',
            'title' => 'a backlog issue',
            'class' => 'RuntimeException',
            'status' => 'open',
            'occurrences' => 1,
            'people' => 0,
            'first_seen_at' => now()->subDays(200),
            'last_seen_at' => now()->subDays(120),
        ], $chunk));
    }
}

// ---- the cache table ---------------------------------------------------------------------------------------------------

/** From now on the cache is kept in the database (as in production). Returns the prefix its keys carry. */
function errorsPruneDatabaseCache(): string
{
    config(['cache.default' => 'database']);

    // The rate limiter was built at boot on the test's array cache: build it again on the one chosen now.
    app()->forgetInstance(LaravelRateLimiter::class);
    Facade::clearResolvedInstance(LaravelRateLimiter::class);

    return (string) config('cache.prefix');
}

/** One row of the cache table; $key is written as given (the prefix is the caller's business). */
function errorsPruneCacheRow(string $key, CarbonInterface $expires, string $table = 'cache'): void
{
    DB::table($table)->insert(['key' => $key, 'value' => serialize(1), 'expiration' => $expires->getTimestamp()]);
}

/** @return list<string> the keys of a cache table, sorted */
function errorsPruneCacheKeys(string $table = 'cache'): array
{
    return DB::table($table)->pluck('key')->sort()->values()->all();
}

describe('occurrences', function () {
    it('keeps a sample up to 30 days old and removes the ones older than that', function () {
        $issue = errorsPruneIssue(['title' => 'seen today']);
        DB::table('error_occurrences')->delete();

        errorsPruneSample($issue, now()->subDays(29), 'sample 29 days');
        errorsPruneSample($issue, now()->subDays(30), 'sample exactly 30 days');
        errorsPruneSample($issue, now()->subDays(30)->subSecond(), 'sample 30 days and a second');
        errorsPruneSample($issue, now()->subDays(31), 'sample 31 days');
        errorsPruneSample($issue, now()->subDays(400), 'sample 400 days');

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 3 occurrences older than 30 days.')->assertSuccessful();

        expect(errorsPruneSamplesLeft())->toBe(['sample 29 days', 'sample exactly 30 days'])
            ->and(errorsPruneLeft())->toBe(['seen today']);
    });

    it('goes by the days setting, read when it runs', function () {
        $issue = errorsPruneIssue();
        DB::table('error_occurrences')->delete();
        errorsPruneSample($issue, now()->subDays(10), 'sample 10 days');
        errorsPruneSample($issue, now()->subDays(40), 'sample 40 days');

        config(['qistas.errors.occurrence_days' => 7]);
        $this->artisan('qistas:errors:prune')->expectsOutputToContain('older than 7 days')->assertSuccessful();

        expect(errorsPruneSamplesLeft())->toBe([]);

        errorsPruneSample($issue, now()->subDays(10), 'sample again');
        config(['qistas.errors.occurrence_days' => 365]);
        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneSamplesLeft())->toBe(['sample again']);
    });
});

describe('open issues', function () {
    it('keeps one seen in the last 90 days, at the edge too, and removes it the moment it is older', function () {
        errorsPruneOpen(0);
        errorsPruneOpen(89);
        errorsPruneOpen(90);
        errorsPruneOpen(90, hours: 1);
        errorsPruneOpen(91);
        errorsPruneOpen(400);
        config(['qistas.errors.occurrence_days' => 365]); // the samples are not what this is about

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 3 open issues not seen for 90 days.')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['open, seen 0 days 0 hours ago', 'open, seen 89 days 0 hours ago', 'open, seen 90 days 0 hours ago']);
    });

    it('never removes an open issue that was seen recently, however old it is', function () {
        errorsPruneIssue(['title' => 'old but alive', 'first_seen_at' => now()->subDays(900), 'last_seen_at' => now()->subMinute()]);
        errorsPruneIssue(['title' => 'seen a day ago', 'first_seen_at' => now()->subDays(900), 'last_seen_at' => now()->subDay()]);
        errorsPruneIssue(['title' => 'seen 20 days ago', 'first_seen_at' => now()->subDays(900), 'last_seen_at' => now()->subDays(20)]);

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['old but alive', 'seen 20 days ago', 'seen a day ago']);
    });

    it('goes by the issue days setting, read when it runs', function () {
        errorsPruneOpen(10);
        errorsPruneOpen(40);

        config(['qistas.errors.issue_days' => 30]);
        $this->artisan('qistas:errors:prune')->expectsOutputToContain('not seen for 30 days')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['open, seen 10 days 0 hours ago']);
    });
});

describe('resolved issues', function () {
    it('keeps one for 30 days after it was resolved, and removes it after that', function () {
        errorsPruneIssue(['title' => 'resolved 29 days ago', 'status' => 'resolved', 'resolved_at' => now()->subDays(29)]);
        errorsPruneIssue(['title' => 'resolved exactly 30 days ago', 'status' => 'resolved', 'resolved_at' => now()->subDays(30)]);
        errorsPruneIssue(['title' => 'resolved 31 days ago', 'status' => 'resolved', 'resolved_at' => now()->subDays(31)]);
        errorsPruneIssue(['title' => 'resolved 400 days ago', 'status' => 'resolved', 'resolved_at' => now()->subDays(400)]);
        config(['qistas.errors.occurrence_days' => 365]);

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 2 resolved issues resolved more than 30 days ago.')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['resolved 29 days ago', 'resolved exactly 30 days ago']);
    });

    it('counts from when it was resolved, not from when it was last seen', function () {
        // Seen long ago but resolved yesterday: it stays a month more, so the decision can be read. Seen yesterday and
        // resolved a month ago: its time is up.
        errorsPruneIssue(['title' => 'seen long ago, resolved yesterday', 'status' => 'resolved', 'last_seen_at' => now()->subDays(200), 'resolved_at' => now()->subDay()]);
        errorsPruneIssue(['title' => 'resolved long ago', 'status' => 'resolved', 'last_seen_at' => now()->subDay(), 'resolved_at' => now()->subDays(45)]);
        config(['qistas.errors.occurrence_days' => 365]);

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['seen long ago, resolved yesterday']);
    });
});

describe('ignored issues', function () {
    it('keeps one for 30 days after its last sighting, and removes it after that', function () {
        errorsPruneIssue(['title' => 'ignored, seen 29 days ago', 'status' => 'ignored', 'last_seen_at' => now()->subDays(29)]);
        errorsPruneIssue(['title' => 'ignored, seen exactly 30 days ago', 'status' => 'ignored', 'last_seen_at' => now()->subDays(30)]);
        errorsPruneIssue(['title' => 'ignored, seen 31 days ago', 'status' => 'ignored', 'last_seen_at' => now()->subDays(31)]);
        errorsPruneIssue(['title' => 'ignored, seen 80 days ago', 'status' => 'ignored', 'last_seen_at' => now()->subDays(80)]);
        config(['qistas.errors.occurrence_days' => 365]);

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 2 ignored issues not seen for 30 days.')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['ignored, seen 29 days ago', 'ignored, seen exactly 30 days ago']);
    });

    it('goes by the last sighting, not by when it was ignored', function () {
        errorsPruneIssue(['title' => 'ignored yesterday, quiet for 40 days', 'status' => 'ignored', 'ignored_at' => now()->subDay(), 'last_seen_at' => now()->subDays(40)]);
        errorsPruneIssue(['title' => 'ignored long ago, still loud', 'status' => 'ignored', 'ignored_at' => now()->subDays(300), 'last_seen_at' => now()->subDays(2)]);
        config(['qistas.errors.occurrence_days' => 365]);

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['ignored long ago, still loud']);
    });
});

describe('each status has its own rule', function () {
    it('applies the 90 days to open issues only, and the 30 days to the others only', function () {
        errorsPruneIssue(['title' => 'open 60', 'last_seen_at' => now()->subDays(60)]);
        errorsPruneIssue(['title' => 'ignored 60', 'status' => 'ignored', 'last_seen_at' => now()->subDays(60)]);
        errorsPruneIssue(['title' => 'resolved 5, seen 60 ago', 'status' => 'resolved', 'last_seen_at' => now()->subDays(60), 'resolved_at' => now()->subDays(5)]);
        errorsPruneIssue(['title' => 'open 100', 'last_seen_at' => now()->subDays(100)]);
        config(['qistas.errors.occurrence_days' => 365]);

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['open 60', 'resolved 5, seen 60 ago']);
    });
});

describe('what hangs on an issue', function () {
    it('goes with the issue: its days, its people and its samples', function () {
        config(['qistas.errors.occurrence_days' => 365]); // so the samples go with the issue, not before it
        $gone = errorsPruneOpen(120);
        $resolved = errorsPruneIssue(['title' => 'resolved long ago', 'status' => 'resolved', 'resolved_at' => now()->subDays(60)]);
        $ignored = errorsPruneIssue(['title' => 'ignored long ago', 'status' => 'ignored', 'last_seen_at' => now()->subDays(60)]);
        $stays = errorsPruneOpen(2);
        errorsPruneSample($gone, now()->subDays(130), 'a second sample of the removed');

        expect(errorsPruneChildren())->toBe(['days' => 4, 'people' => 4, 'samples' => 5]);

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneLeft())->toBe([$stays->title])
            ->and(errorsPruneChildren())->toBe(['days' => 1, 'people' => 1, 'samples' => 1])
            ->and(DB::table('error_issue_days')->value('issue_id'))->toBe($stays->id)
            ->and(DB::table('error_issue_people')->value('issue_id'))->toBe($stays->id)
            ->and(ErrorOccurrence::query()->value('issue_id'))->toBe($stays->id)
            ->and(ErrorIssue::query()->whereKey([$gone->id, $resolved->id, $ignored->id])->exists())->toBeFalse();
    });

    it('leaves the days and the people of an issue that stays', function () {
        $issue = errorsPruneOpen(3);
        DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => now()->subDays(40)->toDateString(), 'count' => 9]);
        errorsPruneSample($issue, now()->subDays(70), 'an old sample');

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        // Only the sample is old by its own clock; the day counts feed the 30-day timeline and go with the issue.
        expect(errorsPruneChildren())->toBe(['days' => 2, 'people' => 1, 'samples' => 1]);
    });
});

describe('scanner noise', function () {
    it('keeps the counts of the last 30 days and removes the older ones', function () {
        errorsPruneNoise('2026-11-04');
        errorsPruneNoise('2026-10-06', 'dropped'); // 29 days ago
        errorsPruneNoise('2026-10-05', 'scanner'); // exactly 30 days ago
        errorsPruneNoise('2026-10-05', 'dropped');
        errorsPruneNoise('2026-10-04', 'scanner'); // 31 days ago
        errorsPruneNoise('2026-10-04', 'dropped');
        errorsPruneNoise('2026-01-15', 'scanner');

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 3 noise rows older than 30 days.')->assertSuccessful();

        expect(DB::table('error_noise_days')->orderBy('day')->orderBy('kind')->get()->map(fn ($row) => $row->day.' '.$row->kind)->all())
            ->toBe(['2026-10-05 dropped', '2026-10-05 scanner', '2026-10-06 dropped', '2026-11-04 scanner']);
    });

    it('is not tied to the issue settings', function () {
        errorsPruneNoise('2026-09-01');
        config(['qistas.errors.occurrence_days' => 365, 'qistas.errors.issue_days' => 365]);

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(DB::table('error_noise_days')->count())->toBe(0);
    });
});

describe('the output', function () {
    it('says what it removed, one line for each kind', function () {
        $issue = errorsPruneIssue(['title' => 'seen today']);
        errorsPruneSample($issue, now()->subDays(50));
        errorsPruneIssue(['title' => 'o', 'last_seen_at' => now()->subDays(100)]);
        errorsPruneIssue(['title' => 'r', 'status' => 'resolved', 'resolved_at' => now()->subDays(40)]);
        errorsPruneIssue(['title' => 'i', 'status' => 'ignored', 'last_seen_at' => now()->subDays(40)]);
        errorsPruneNoise('2026-09-01');

        $this->artisan('qistas:errors:prune')
            ->expectsOutputToContain('Removed 1 open issue not seen for 90 days.')
            ->expectsOutputToContain('Removed 1 resolved issue resolved more than 30 days ago.')
            ->expectsOutputToContain('Removed 1 ignored issue not seen for 30 days.')
            ->expectsOutputToContain('Removed 1 noise row older than 30 days.')
            ->assertSuccessful();
    });

    it('says zero when there is nothing to remove, and succeeds', function () {
        errorsPruneIssue();

        $this->artisan('qistas:errors:prune')
            ->expectsOutputToContain('Removed 0 occurrences older than 30 days.')
            ->expectsOutputToContain('Removed 0 open issues not seen for 90 days.')
            ->assertSuccessful();

        expect(errorsPruneLeft())->toBe(['an issue']);
    });

    it('runs again with nothing left to do', function () {
        errorsPruneOpen(120);

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 1 open issue')->assertSuccessful();
        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 0 open issues')->assertSuccessful();
    });
});

describe('a setting below 1', function () {
    it('makes it refuse, and remove nothing at all', function (string $setting, mixed $value) {
        $names = ['occurrence_days' => 'QISTAS_ERROR_OCCURRENCE_DAYS', 'issue_days' => 'QISTAS_ERROR_ISSUE_DAYS'];
        $issue = errorsPruneOpen(500);
        errorsPruneSample($issue, now()->subDays(500), 'a very old sample');
        errorsPruneIssue(['title' => 'resolved', 'status' => 'resolved', 'resolved_at' => now()->subDays(90)]);
        errorsPruneIssue(['title' => 'ignored', 'status' => 'ignored', 'last_seen_at' => now()->subDays(90)]);
        errorsPruneNoise('2026-01-01');
        errorsPruneDatabaseCache();
        errorsPruneCacheRow((string) config('cache.prefix').'errors:hit:abc', now()->subDay());
        $rows = fn () => [DB::table('error_issues')->count(), DB::table('error_occurrences')->count(), DB::table('error_noise_days')->count(), DB::table('cache')->count()];
        $before = $rows();

        config(["qistas.errors.{$setting}" => $value]);

        $this->artisan('qistas:errors:prune')
            ->expectsOutputToContain($names[$setting])
            ->expectsOutputToContain('Nothing was removed.')
            ->assertFailed();

        expect($rows())->toBe($before);
    })->with(['occurrence_days', 'issue_days'])->with(['zero' => 0, 'negative' => -5, 'empty' => '', 'text' => 'forever', 'null' => null]);

    it('is read as the sign-in prune reads it: a whole number from text works', function () {
        errorsPruneOpen(40);

        config(['qistas.errors.issue_days' => '30']);
        $this->artisan('qistas:errors:prune')->assertSuccessful();

        expect(errorsPruneLeft())->toBe([]);
    });
});

describe('a large backlog', function () {
    it('is removed in batches of ids, a statement for each, and never read into memory whole', function () {
        $total = ErrorsPruneCommand::BATCH * 2 + 300;
        errorsPruneBulk($total, []);
        errorsPruneIssue(['title' => 'recent']);
        $deletes = 0;
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            $deletes += preg_match('/^delete from ["`]?error_issues["`]?\s/i', $query->sql);
        });

        $this->artisan('qistas:errors:prune')->expectsOutputToContain("Removed {$total} open issues not seen for 90 days.")->assertSuccessful();

        expect($deletes)->toBe(3)
            ->and(errorsPruneLeft())->toBe(['recent']);
    });

    it('removes a backlog of old samples the same way', function () {
        $issue = errorsPruneIssue(['title' => 'recent']);
        DB::table('error_occurrences')->delete();
        $total = ErrorsPruneCommand::BATCH + 50;

        foreach (array_chunk(range(1, $total), 100) as $chunk) {
            DB::table('error_occurrences')->insert(array_map(fn () => [
                'id' => (string) Str::uuid7(), 'issue_id' => $issue->id, 'occurred_at' => now()->subDays(45), 'status' => 500,
                'message' => 'old', 'trace' => '', 'platform' => 'server', 'created_at' => now()->subDays(45),
            ], $chunk));
        }

        $this->artisan('qistas:errors:prune')->expectsOutputToContain("Removed {$total} occurrences older than 30 days.")->assertSuccessful();

        expect(DB::table('error_occurrences')->count())->toBe(0);
    });

    it('asks for each status through the status and last-seen index', function () {
        errorsPruneOpen(120);
        $selects = [];
        DB::listen(function (QueryExecuted $query) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'error_issues')) {
                $selects[] = [$query->sql, $query->bindings];
            }
        });

        $this->artisan('qistas:errors:prune')->assertSuccessful();

        $plans = collect($selects)->map(fn (array $select) => implode(' | ', array_map(
            fn (object $row) => $row->detail,
            DB::select('explain query plan '.$select[0], $select[1]),
        )));

        // One lookup for each of the three statuses, each answered from the (status, last_seen_at) index: no table scan.
        expect($plans)->toHaveCount(3);

        foreach ($plans as $plan) {
            expect($plan)->toContain('error_issues_status_last_seen_at_index')->not->toContain('SCAN');
        }
    })->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'reads the SQLite query plan');
});

describe('the cache table', function () {
    it('removes what the error center left there once it has expired, and only that', function () {
        $prefix = errorsPruneDatabaseCache();
        $gone = [
            'errors:hit:'.sha1('a'), 'errors:new-issues:alert:minute:202611040930', 'errors:new-issues:script:hour:2026110409',
            'errors:sender:0123456789abcdef0123456789abcdef:2026110409', 'error-alerts-hour', 'error-alerts-hour:timer',
            'error-alerts-day', 'error-alerts-day:timer',
        ];
        $kept = [
            'settings:platform', 'login|203.0.113.9', 'errors', 'errorsfoo', 'my-errors:thing', 'error-alert', 'error-alerts', 'xerror-alerts-hour',
            'illuminate:cache:flexible:created:errors:hit:a', 'laravel_cache_errors:hit:b',
        ];

        foreach ($gone as $key) {
            errorsPruneCacheRow($prefix.$key, now()->subMinute());
        }

        foreach ($kept as $key) {
            errorsPruneCacheRow($prefix.$key, now()->subMinute());
        }

        // The same names, still alive, and a key of another prefix that only looks like ours.
        errorsPruneCacheRow($prefix.'errors:hit:alive', now()->addMinute());
        errorsPruneCacheRow($prefix.'error-alerts-hour:alive', now()->addDay());
        errorsPruneCacheRow('errors:hit:without-the-prefix', now()->subDay());
        errorsPruneCacheRow('other-cache-errors:hit:1', now()->subDay());

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 8 expired error-center rows from the cache.')->assertSuccessful();

        $expected = array_merge(
            array_map(fn (string $key) => $prefix.$key, $kept),
            [$prefix.'errors:hit:alive', $prefix.'error-alerts-hour:alive', 'errors:hit:without-the-prefix', 'other-cache-errors:hit:1'],
        );
        sort($expected);

        expect(errorsPruneCacheKeys())->toBe($expected);
    });

    it('removes the rows its own writers leave, which is how the key names are kept honest', function () {
        $prefix = errorsPruneDatabaseCache();

        // The recorder's own counters (a place in the minute's and the hour's budget, and in a sender's), a repeat marker as the
        // capture layer writes it, and the alert counters of the instant e-mails.
        expect(app(ErrorRecorder::class)->record(new ErrorEvent(source: 'site', kind: 'script', class: 'TypeError', message: 'x is undefined', platform: 'web'), 'address:203.0.113.9'))
            ->toBe(Recorded::Created);
        Cache::add('errors:hit:'.sha1('x'), 1, 60);
        RateLimiter::hit(ErrorAlerts::HOUR_KEY, ErrorAlerts::HOUR_SECONDS);
        RateLimiter::hit(ErrorAlerts::DAY_KEY, ErrorAlerts::DAY_SECONDS);
        Cache::put('other:feature', 'kept', 60);
        Cache::forever('forever:feature', 'kept');

        $ours = collect(errorsPruneCacheKeys())->filter(fn (string $key) => str_starts_with($key, $prefix.'error'))->values();
        expect($ours->count())->toBeGreaterThanOrEqual(8);

        // Nothing has expired yet.
        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 0 expired error-center rows from the cache.')->assertSuccessful();
        expect(errorsPruneCacheKeys())->toHaveCount(count($ours) + 2);

        $this->travelTo(now()->addHours(26));
        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed '.$ours->count().' expired error-center rows from the cache.')->assertSuccessful();

        expect(errorsPruneCacheKeys())->toBe([$prefix.'forever:feature', $prefix.'other:feature']);
    });

    it('reads the table, the prefix and the connection from the cache settings', function () {
        errorsPruneDatabaseCache();
        config(['cache.prefix' => 'qa_1%-', 'cache.stores.database.table' => 'errors_prune_cache']);
        Schema::create('errors_prune_cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        errorsPruneCacheRow('qa_1%-errors:hit:ours', now()->subMinute(), 'errors_prune_cache');
        errorsPruneCacheRow('qa_1%-error-alerts-day:timer', now()->subMinute(), 'errors_prune_cache');
        errorsPruneCacheRow('qa_1%-other', now()->subMinute(), 'errors_prune_cache');
        // "_" and "%" are not wildcards here: these only look like the prefix.
        errorsPruneCacheRow('qaX1ZZ-errors:hit:decoy', now()->subMinute(), 'errors_prune_cache');
        // The default table is not the configured one: nothing of it is touched.
        errorsPruneCacheRow('qa_1%-errors:hit:in-the-other-table', now()->subMinute(), 'cache');

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 2 expired error-center rows from the cache.')->assertSuccessful();

        expect(errorsPruneCacheKeys('errors_prune_cache'))->toBe(['qaX1ZZ-errors:hit:decoy', 'qa_1%-other'])
            ->and(errorsPruneCacheKeys('cache'))->toBe(['qa_1%-errors:hit:in-the-other-table']);
    });

    it('takes a database store under any name, with its own prefix', function () {
        config([
            'cache.default' => 'main',
            'cache.stores.main' => ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'prefix' => 'main-'],
            'cache.prefix' => 'unused-',
        ]);
        errorsPruneCacheRow('main-errors:hit:1', now()->subMinute());
        errorsPruneCacheRow('unused-errors:hit:2', now()->subMinute());

        $this->artisan('qistas:errors:prune')->expectsOutputToContain('Removed 1 expired error-center row from the cache.')->assertSuccessful();

        expect(errorsPruneCacheKeys())->toBe(['unused-errors:hit:2']);
    });

    it('does nothing when the cache is kept elsewhere', function (string $store) {
        $prefix = (string) config('cache.prefix');
        errorsPruneCacheRow($prefix.'errors:hit:1', now()->subDay());
        errorsPruneCacheRow($prefix.'error-alerts-hour', now()->subDay());
        config(['cache.default' => $store]);

        $this->artisan('qistas:errors:prune')->doesntExpectOutputToContain('from the cache')->assertSuccessful();

        expect(errorsPruneCacheKeys())->toHaveCount(2);
    })->with(['array', 'file', 'null']);

    it('does not touch the cache table when it refuses to run', function () {
        $prefix = errorsPruneDatabaseCache();
        errorsPruneCacheRow($prefix.'errors:hit:1', now()->subDay());
        config(['qistas.errors.issue_days' => 0]);

        $this->artisan('qistas:errors:prune')->assertFailed();

        expect(errorsPruneCacheKeys())->toBe([$prefix.'errors:hit:1']);
    });
});

describe('the schedule', function () {
    it('runs the prune every day, the spool import every five minutes and the digest every day at 04:00, one run at a time', function () {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $command) => $events->filter(fn ($scheduled) => str_contains((string) $scheduled->command, $command))->sole();

        // The digest is meant for 07:00 in the Gulf, which is 04:00 on the app's clock: the app's timezone is UTC.
        expect(config('app.timezone'))->toBe('UTC');

        $prune = $find('qistas:errors:prune');
        $import = $find('qistas:errors:import-spool');
        $digest = $find('qistas:errors:digest');

        expect($prune->expression)->toBe('0 0 * * *')
            ->and($import->expression)->toBe('*/5 * * * *')
            ->and($digest->expression)->toBe('0 4 * * *')
            ->and([$prune->withoutOverlapping, $import->withoutOverlapping, $digest->withoutOverlapping])->toBe([true, true, true])
            ->and($events->filter(fn ($e) => str_contains((string) $e->command, 'qistas:errors:'))->count())->toBe(3);
    });
});
