<?php

use App\Errors\ErrorEvent;
use App\Errors\ErrorFingerprint;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorScrubber;
use App\Errors\ErrorSpool;
use App\Errors\Events\ErrorIssueCreated;
use App\Errors\Recorded;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
| The recorder is the one way into the error center's tables (design sections 4, 5 and 8): it scrubs, fingerprints, and
| in one transaction opens or counts the issue, keeps the newest five samples, counts the day and the person. These tests
| pin what a stored issue holds after each kind of sighting, the flood caps, and what "off" means. What happens when the
| database is away (the spool) is ErrorSpoolTest.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30:00', 'UTC'));
    $this->spool = errorsRecorderSpool();
    ErrorSpool::forgetLost(ErrorSpool::lost()); // a count another test left in this process is not this test's
});

afterEach(function () {
    errorsRecorderForget($this->spool);
    ErrorSpool::forgetLost(ErrorSpool::lost());
});

/** An error page for a path no route serves; each $n is a different path, so a different issue. */
function errorsRecorderPage(int $n, int $status = 404): array
{
    return ['kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => $status, 'method' => 'GET', 'route' => "/missing/page-{$n}"];
}

/** A slow query on a table of its own (named in letters: digits are folded away by the fingerprint). */
function errorsRecorderSlowQuery(int $n): array
{
    $word = '';

    do {
        $word = chr(97 + $n % 26).$word;
        $n = intdiv($n, 26);
    } while ($n > 0);

    return [
        'source' => 'database', 'kind' => 'slow_query', 'class' => '', 'message' => "select * from table_{$word} where id = ?",
        'trace' => '', 'status' => null, 'method' => null, 'route' => null,
    ];
}

/** A server error the instant e-mail is for: the API answered 503. */
function errorsRecorderOutage(string $route = '/api/v1/contracts'): array
{
    return ['source' => 'api', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 503, 'method' => 'GET', 'route' => $route];
}

/**
 * A connection that says it is PostgreSQL (on an SQLite database in memory) and writes down every lock timeout it is
 * told to set, with the transaction level it was told at.
 */
function errorsRecorderPgsqlDouble(string $driver = 'pgsql'): SQLiteConnection
{
    return new class(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => $driver, 'name' => 'errors-'.$driver.'-double']) extends SQLiteConnection
    {
        /** @var list<array{0: string, 1: int}> */
        public array $said = [];

        public function unprepared($query)
        {
            if (str_contains($query, 'lock_timeout')) {
                $this->said[] = [$query, $this->transactionLevel()];

                return true;
            }

            return parent::unprepared($query);
        }
    };
}

/** An event the way the capture layer builds one, any field replaced. It happens now unless told otherwise. */
function errorsRecorderEvent(array $with = []): ErrorEvent
{
    return new ErrorEvent(...($with + [
        'source' => 'site',
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'message' => 'Something broke',
        'trace' => "#0 app/Actions/Pay.php(48): App\\Actions\\Pay->handle()\n#1 {main}",
        'status' => 500,
        'method' => 'POST',
        'route' => '/app/contracts/{contract}/payments',
        'version' => '1.30.0',
        'platform' => 'server',
        'device' => null,
        'userId' => null,
    ]));
}

function errorsRecord(array $with = []): Recorded
{
    return app(ErrorRecorder::class)->record(errorsRecorderEvent($with));
}

/** The spool of this test alone (in the system's temporary folder), so no test ever writes storage/logs. */
function errorsRecorderSpool(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-recorder-'.Str::random(12).DIRECTORY_SEPARATOR.'error-spool.ndjson';
    config(['qistas.errors.spool_path' => $path]);

    return $path;
}

function errorsRecorderForget(string $path): void
{
    foreach ([$path, $path.'.lock'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    if (is_dir(dirname($path))) {
        rmdir(dirname($path));
    }
}

/** The person hash the design gives: 16 hex characters of an HMAC-SHA256 of the user id with the application key. */
function errorsRecorderPerson(string $userId): string
{
    return substr(hash_hmac('sha256', $userId, config('app.key')), 0, 16);
}

/** Every row of the five tables as one text: all that a stored record holds. */
function errorsRecorderStored(): string
{
    return collect(['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'])
        ->map(fn (string $table) => DB::table($table)->get()->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->implode("\n");
}

function errorsRecorderNoise(string $kind): int
{
    return (int) DB::table('error_noise_days')->where('kind', $kind)->sum('count');
}

/** The days of an issue as day => count. */
function errorsRecorderDays(ErrorIssue $issue): array
{
    return DB::table('error_issue_days')->where('issue_id', $issue->id)->orderBy('day')->get()
        ->mapWithKeys(fn (object $row) => [substr((string) $row->day, 0, 10) => (int) $row->count])->all();
}

// ---- one issue, its samples, days and people ----------------------------------------------------------------------

it('a first failure creates an issue with one occurrence, one day row and one person', function () {
    $userId = (string) Str::uuid();

    expect(errorsRecord(['userId' => $userId]))->toBe(Recorded::Created);

    $issue = ErrorIssue::query()->sole();
    $sample = $issue->samples()->sole();

    expect($issue->only(['source', 'kind', 'class', 'status', 'occurrences', 'people', 'first_version', 'last_version']))->toBe([
        'source' => 'site', 'kind' => 'exception', 'class' => 'RuntimeException', 'status' => 'open',
        'occurrences' => 1, 'people' => 1, 'first_version' => '1.30.0', 'last_version' => '1.30.0',
    ])
        ->and($issue->title)->toBe('RuntimeException: Something broke')
        ->and($issue->fingerprint)->toBe(ErrorFingerprint::of(ErrorScrubber::event(errorsRecorderEvent())))
        ->and($issue->first_seen_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and($issue->last_seen_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and($sample->only(['status', 'method', 'route', 'message', 'version', 'platform', 'device']))->toBe([
            'status' => 500, 'method' => 'POST', 'route' => '/app/contracts/{contract}/payments', 'message' => 'Something broke',
            'version' => '1.30.0', 'platform' => 'server', 'device' => null,
        ])
        ->and($sample->trace)->toContain('app/Actions/Pay.php(48)')
        ->and($sample->occurred_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and(errorsRecorderDays($issue))->toBe(['2026-11-04' => 1])
        ->and(DB::table('error_issue_people')->where('issue_id', $issue->id)->pluck('person')->all())->toBe([errorsRecorderPerson($userId)]);
});

it('the same failure again only increments', function () {
    $first = (string) Str::uuid();
    errorsRecord(['userId' => $first]);
    $id = ErrorIssue::query()->sole()->id;

    $this->travel(5)->minutes();
    expect(errorsRecord(['userId' => (string) Str::uuid()]))->toBe(Recorded::Counted);

    $this->travel(1)->minutes();
    expect(errorsRecord(['userId' => $first]))->toBe(Recorded::Counted); // the same person again is still one person

    $this->travel(1)->minutes();
    expect(errorsRecord())->toBe(Recorded::Counted); // and a sighting with nobody signed in counts nobody

    $issue = ErrorIssue::query()->sole();

    expect($issue->id)->toBe($id)
        ->and($issue->occurrences)->toBe(4)
        ->and($issue->people)->toBe(2)
        ->and(DB::table('error_issue_people')->where('issue_id', $id)->count())->toBe(2)
        ->and($issue->samples()->count())->toBe(4)
        ->and($issue->first_seen_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and($issue->last_seen_at->toDateTimeString())->toBe('2026-11-04 10:37:00')
        ->and(errorsRecorderDays($issue))->toBe(['2026-11-04' => 4]);
});

it('each day of an issue has a count of its own', function () {
    errorsRecord();
    errorsRecord();
    $this->travel(1)->days();
    errorsRecord();

    expect(errorsRecorderDays(ErrorIssue::query()->sole()))->toBe(['2026-11-04' => 2, '2026-11-05' => 1]);
});

it('a sixth occurrence deletes the oldest sample', function () {
    foreach (range(1, 6) as $minute) {
        errorsRecord(['message' => "Something broke {$minute}"]); // digits are folded: one issue
        $this->travel(1)->minutes();
    }

    $issue = ErrorIssue::query()->sole();

    expect($issue->occurrences)->toBe(6)
        ->and(ErrorOccurrence::query()->count())->toBe(5)
        ->and($issue->samples()->pluck('message')->all())->toBe([
            'Something broke 6', 'Something broke 5', 'Something broke 4', 'Something broke 3', 'Something broke 2',
        ]);
});

it('samples that share a second are trimmed in the order of their time, then their id', function () {
    foreach (range(1, 5) as $n) {
        errorsRecord(); // the clock is frozen: all in one second
    }

    $before = ErrorOccurrence::query()->pluck('id')->all();
    errorsRecord();
    $after = ErrorOccurrence::query()->pluck('id')->all();

    $all = array_values(array_unique([...$before, ...$after]));
    sort($all);
    sort($after);

    expect($all)->toHaveCount(6)
        ->and($after)->toBe(array_slice($all, 1)); // the one with the smallest id went
});

it('trimming the samples of one issue leaves every other issue\'s samples alone', function () {
    foreach (range(1, 5) as $minute) {
        errorsRecord(['class' => 'AlphaException']);
        errorsRecord(['class' => 'BravoException']);
        $this->travel(1)->minutes();
    }

    errorsRecord(['class' => 'AlphaException']); // a sixth for Alpha: Alpha's oldest goes, and nothing of Bravo's

    $alpha = ErrorIssue::query()->where('class', 'AlphaException')->sole();
    $bravo = ErrorIssue::query()->where('class', 'BravoException')->sole();

    expect($alpha->samples()->count())->toBe(5)
        ->and($bravo->samples()->count())->toBe(5)
        ->and(ErrorOccurrence::query()->count())->toBe(10)
        ->and($alpha->samples()->get()->last()->occurred_at->toDateTimeString())->toBe('2026-11-04 10:31:00');
});

it('the counters are added to in the database, never written back as a number read before', function () {
    errorsRecord(['userId' => (string) Str::uuid()]);
    $issue = ErrorIssue::query()->sole();
    $bumped = false;

    // Another write lands between the recorder's read of the issue and its own write.
    DB::listen(function (QueryExecuted $query) use (&$bumped, $issue): void {
        if ($bumped || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! in_array($issue->fingerprint, $query->bindings, true)) {
            return;
        }

        $bumped = true;
        DB::table('error_issues')->where('id', $issue->id)->update([
            'occurrences' => DB::raw('occurrences + 10'),
            'people' => DB::raw('people + 10'),
        ]);
    });

    expect(errorsRecord(['userId' => (string) Str::uuid()]))->toBe(Recorded::Counted)
        ->and($bumped)->toBeTrue()
        ->and($issue->fresh()->occurrences)->toBe(12)
        ->and($issue->fresh()->people)->toBe(12);
});

it('the person count stops at 500', function () {
    errorsRecord(['userId' => (string) Str::uuid()]);
    $issue = ErrorIssue::query()->sole();

    DB::table('error_issue_people')->insert(collect(range(1, 499))
        ->map(fn (int $n) => ['issue_id' => $issue->id, 'person' => str_pad(dechex($n), 16, '0', STR_PAD_LEFT)])->all());
    $issue->forceFill(['people' => 500])->save();

    expect(errorsRecord(['userId' => (string) Str::uuid()]))->toBe(Recorded::Counted); // the 501st person

    expect($issue->fresh()->people)->toBe(500)
        ->and($issue->fresh()->occurrences)->toBe(2)
        ->and(DB::table('error_issue_people')->where('issue_id', $issue->id)->count())->toBe(500);
});

it('the person hash is recognised in the form the spool writes it', function () {
    $userId = (string) Str::uuid();
    $hash = errorsRecorderPerson($userId);

    expect(ErrorRecorder::person($userId))->toBe($hash)
        ->and(ErrorRecorder::person('h:'.$hash))->toBe($hash)
        ->and(ErrorRecorder::spooledPerson($userId))->toBe('h:'.$hash)
        ->and(ErrorRecorder::spooledPerson('h:'.$hash))->toBe('h:'.$hash)
        ->and(ErrorRecorder::person(null))->toBeNull()
        ->and(ErrorRecorder::person(''))->toBeNull()
        ->and(ErrorRecorder::spooledPerson(null))->toBeNull()
        // only exactly `h:` and 16 lowercase hex characters is the spooled form; anything else is an id to hash
        ->and(ErrorRecorder::person('h:'.strtoupper($hash)))->toBe(errorsRecorderPerson('h:'.strtoupper($hash)))
        ->and(ErrorRecorder::person('h:'.$hash.'0'))->toBe(errorsRecorderPerson('h:'.$hash.'0'));

    errorsRecord(['userId' => $userId]);
    errorsRecord(['userId' => 'h:'.$hash]); // the same person, read back from the spool

    expect(ErrorIssue::query()->sole()->people)->toBe(1)
        ->and(DB::table('error_issue_people')->pluck('person')->all())->toBe([$hash]);
});

// ---- grouping ------------------------------------------------------------------------------------------------------

it('ids and numbers in the message do not split the issue', function () {
    errorsRecord(['message' => 'No payment 123 for contract 0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f']);
    errorsRecord(['message' => 'No payment 98765 for contract 5f3e2d1c-0b9a-4c8d-9e7f-6a5b4c3d2e1f']);

    $issue = ErrorIssue::query()->sole();

    expect($issue->occurrences)->toBe(2)
        ->and($issue->title)->toBe('RuntimeException: No payment <n> for contract [id]');
});

it('two different classes make two issues', function () {
    errorsRecord(['class' => 'RuntimeException']);
    errorsRecord(['class' => 'LogicException']);

    expect(ErrorIssue::query()->count())->toBe(2)
        ->and(ErrorIssue::query()->orderBy('class')->pluck('class')->all())->toBe(['LogicException', 'RuntimeException'])
        ->and(ErrorIssue::query()->pluck('occurrences')->map(fn ($count) => (int) $count)->all())->toBe([1, 1]);
});

it('an error page is grouped by its route and keeps its status as the class', function () {
    $page = ['kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 404, 'method' => 'GET', 'route' => '/pricing-typo'];

    expect(errorsRecord($page))->toBe(Recorded::Created)
        ->and(errorsRecord($page))->toBe(Recorded::Counted);

    $issue = ErrorIssue::query()->sole();

    expect($issue->only(['kind', 'class', 'title', 'occurrences']))->toBe([
        'kind' => 'http', 'class' => 'http.404', 'title' => '404 Not Found: /pricing-typo', 'occurrences' => 2,
    ])
        ->and($issue->samples()->first()->only(['status', 'route', 'message', 'trace']))->toBe([
            'status' => 404, 'route' => '/pricing-typo', 'message' => '', 'trace' => '',
        ]);
});

// ---- statuses ------------------------------------------------------------------------------------------------------

it('an ignored issue stays silent and uncounted', function () {
    errorsRecord(['userId' => (string) Str::uuid()]);
    $issue = ErrorIssue::query()->sole();
    $issue->forceFill(['status' => 'ignored', 'ignored_at' => now()])->save();
    $before = errorsRecorderStored();

    Event::fake([ErrorIssueCreated::class]);
    $this->travel(1)->days();

    expect(errorsRecord(['userId' => (string) Str::uuid()]))->toBe(Recorded::Ignored)
        ->and(errorsRecorderStored())->toBe($before) // not a count, a sample, a day, a person or a last-seen time
        ->and($issue->fresh()->status)->toBe('ignored');
    Event::assertNotDispatched(ErrorIssueCreated::class);
});

it('a resolved issue reopens with the version it came back in and fires no ErrorIssueCreated', function () {
    errorsRecord(['version' => '1.29.0']);
    $issue = ErrorIssue::query()->sole();
    $issue->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => (string) Str::uuid()])->save();

    Event::fake([ErrorIssueCreated::class]);
    $this->travel(2)->hours();

    expect(errorsRecord(['version' => '1.30.0']))->toBe(Recorded::Reopened);

    $issue = $issue->fresh();

    expect($issue->status)->toBe('open')
        ->and($issue->reopened_at?->toDateTimeString())->toBe('2026-11-04 12:30:00')
        ->and($issue->reopened_version)->toBe('1.30.0')
        ->and($issue->occurrences)->toBe(2)
        ->and($issue->last_version)->toBe('1.30.0');
    Event::assertNotDispatched(ErrorIssueCreated::class);

    $this->travel(1)->minutes();
    expect(errorsRecord(['version' => '1.30.0']))->toBe(Recorded::Counted); // open again: an ordinary sighting
});

it('a sighting from before the issue was resolved is counted but does not reopen it', function () {
    errorsRecord(['version' => '1.29.0']);
    $issue = ErrorIssue::query()->sole();
    $issue->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

    $this->travel(10)->minutes();

    // Read back from the spool: it happened while the database was away, before someone resolved the issue.
    expect(errorsRecord(['at' => '2026-11-04T10:20:00+00:00']))->toBe(Recorded::Counted);

    expect($issue->fresh()->status)->toBe('resolved')
        ->and($issue->fresh()->reopened_at)->toBeNull()
        ->and($issue->fresh()->occurrences)->toBe(2);
});

// ---- the event ---------------------------------------------------------------------------------------------------

it('ErrorIssueCreated fires once per new issue, with the stored issue', function () {
    Event::fake([ErrorIssueCreated::class]);

    errorsRecord(['class' => 'RuntimeException']);
    errorsRecord(['class' => 'RuntimeException']);
    errorsRecord(['class' => 'LogicException']);

    Event::assertDispatchedTimes(ErrorIssueCreated::class, 2);
    Event::assertDispatched(ErrorIssueCreated::class, fn (ErrorIssueCreated $event) => $event->issue->class === 'RuntimeException'
        && $event->issue->is(ErrorIssue::query()->where('class', 'RuntimeException')->sole()));
    Event::assertDispatched(ErrorIssueCreated::class, fn (ErrorIssueCreated $event) => $event->issue->class === 'LogicException');
});

it('ErrorIssueCreated fires after the transaction committed, and a listener that throws changes nothing', function () {
    $level = DB::transactionLevel();
    $seen = null;

    Event::listen(ErrorIssueCreated::class, function (ErrorIssueCreated $event) use (&$seen): void {
        $seen = [DB::transactionLevel(), ErrorIssue::query()->whereKey($event->issue->id)->exists()];

        throw new RuntimeException('The mail server is away.');
    });

    expect(errorsRecord())->toBe(Recorded::Created)
        ->and($seen)->toBe([$level, true])
        ->and(ErrorIssue::query()->sole()->occurrences)->toBe(1)
        ->and(ErrorRecorder::busy())->toBeFalse();
});

it('inside a caller\'s transaction, ErrorIssueCreated waits for that commit, and a listener that throws never reaches the caller', function () {
    $level = DB::transactionLevel();
    $fired = [];

    Event::listen(ErrorIssueCreated::class, function () use (&$fired): void {
        $fired[] = DB::transactionLevel();

        throw new RuntimeException('The mail server is away.');
    });

    DB::transaction(function () use (&$fired): void {
        expect(errorsRecord())->toBe(Recorded::Created)
            ->and($fired)->toBe([]); // the caller has not committed yet: nobody is told about an issue that may vanish
    });

    expect($fired)->toBe([$level])
        ->and(ErrorIssue::query()->count())->toBe(1);
});

it('ErrorIssueCreated can be handed to a queued listener: it carries the issue by its key', function () {
    errorsRecord();
    $issue = ErrorIssue::query()->sole();

    $event = unserialize(serialize(new ErrorIssueCreated($issue)));

    expect(class_uses(ErrorIssueCreated::class))->toContain(SerializesModels::class)
        ->and($event->issue->is($issue))->toBeTrue()
        ->and($event->issue->occurrences)->toBe(1);
});

it('on SQLite no lock timeout is set', function () {
    $said = [];
    DB::listen(function (QueryExecuted $query) use (&$said): void {
        $said[] = strtolower($query->sql);
    });

    errorsRecord();
    errorsRecord();
    app(ErrorRecorder::class)->noise('scanner');

    expect($said)->not->toBeEmpty()
        ->and(array_values(array_filter($said, fn (string $sql) => str_contains($sql, 'lock_timeout'))))->toBe([]);

    // The tests run inside a transaction of their own, so the recorder's are never the outermost here: the same question
    // asked at the outermost level, of a connection that is SQLite.
    $sqlite = errorsRecorderPgsqlDouble('sqlite');
    ErrorRecorder::transaction($sqlite, fn () => null);

    expect($sqlite->said)->toBe([]);
});

it('on PostgreSQL the outermost of the recorder\'s transactions waits for a lock at most a second, and a nested one leaves the caller\'s setting alone', function () {
    $db = errorsRecorderPgsqlDouble();

    expect(ErrorRecorder::transaction($db, fn () => 'answer'))->toBe('answer'); // the outermost: set once
    $db->transaction(fn () => ErrorRecorder::transaction($db, fn () => null)); // inside a caller's: SET LOCAL would change the caller's
    ErrorRecorder::transaction($db, fn () => ErrorRecorder::transaction($db, fn () => null)); // the outer sets it, the inner does not

    expect(fn () => ErrorRecorder::transaction($db, fn () => throw new RuntimeException('canceling statement due to lock timeout')))
        ->toThrow(RuntimeException::class);

    expect($db->said)->toBe([
        ["set local lock_timeout = '1s'", 1],
        ["set local lock_timeout = '1s'", 1],
        ["set local lock_timeout = '1s'", 1],
    ])
        ->and($db->transactionLevel())->toBe(0); // a refused one is rolled back, nothing is left open
});

it('a call made while the recorder is at work writes nothing', function () {
    $inner = null;

    // A listener (an alert whose mail fails, a query listener) that would record something of its own.
    Event::listen(ErrorIssueCreated::class, function () use (&$inner): void {
        $inner = app(ErrorRecorder::class)->record(errorsRecorderEvent(['class' => 'App\\Mail\\AlertFailed']));
    });

    expect(errorsRecord())->toBe(Recorded::Created)
        ->and($inner)->toBe(Recorded::Dropped)
        ->and(ErrorIssue::query()->pluck('class')->all())->toBe(['RuntimeException'])
        ->and(DB::table('error_noise_days')->count())->toBe(0)
        ->and(ErrorRecorder::busy())->toBeFalse();
});

// ---- flood control -------------------------------------------------------------------------------------------------

it('the 61st new issue in a minute is dropped and counted as dropped', function () {
    foreach (range(1, 60) as $n) {
        expect(errorsRecord(['class' => "App\\Exceptions\\Flood{$n}"]))->toBe(Recorded::Created);
    }

    expect(errorsRecord(['class' => 'App\\Exceptions\\Flood61']))->toBe(Recorded::Dropped)
        ->and(ErrorIssue::query()->count())->toBe(60)
        ->and(errorsRecorderNoise('dropped'))->toBe(1)
        ->and(DB::table('error_noise_days')->where('kind', 'dropped')->value('day'))->toStartWith('2026-11-04');

    $this->travel(1)->minutes(); // a new minute, a new budget

    expect(errorsRecord(['class' => 'App\\Exceptions\\Flood62']))->toBe(Recorded::Created);
});

it('the 501st new issue in an hour is dropped', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC'));
    $n = 0;

    foreach (range(0, 8) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')->addMinutes($minute));

        foreach (range(1, $minute < 8 ? 60 : 20) as $ignored) {
            $n++;
            expect(errorsRecord(['class' => "App\\Exceptions\\Hourly{$n}"]))->toBe(Recorded::Created);
        }
    }

    expect($n)->toBe(500)
        // 21 in this minute: well under the minute's 60, so it is the hour's cap that holds it
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Hourly501']))->toBe(Recorded::Dropped)
        ->and(ErrorIssue::query()->count())->toBe(500)
        ->and(errorsRecorderNoise('dropped'))->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 11:00:00', 'UTC'));

    expect(errorsRecord(['class' => 'App\\Exceptions\\Hourly502']))->toBe(Recorded::Created);
});

it('client-reported sources (script, crash) stop at 20 new issues a minute', function () {
    $script = fn (int $n) => ['source' => 'site', 'kind' => 'script', 'platform' => 'web', 'class' => "TypeError{$n}", 'trace' => '', 'status' => null];
    $crash = fn (int $n) => ['source' => 'app', 'kind' => 'crash', 'platform' => 'android', 'device' => 'mobile', 'class' => "StateError{$n}", 'trace' => '', 'status' => null];

    foreach (range(1, 10) as $n) {
        expect(errorsRecord($script($n)))->toBe(Recorded::Created)
            ->and(errorsRecord($crash($n)))->toBe(Recorded::Created);
    }

    expect(errorsRecord($script(11)))->toBe(Recorded::Dropped)
        ->and(errorsRecord($crash(11)))->toBe(Recorded::Dropped)
        ->and(errorsRecord($script(1)))->toBe(Recorded::Counted) // one it knows still counts
        ->and(ErrorIssue::query()->count())->toBe(20)
        ->and(errorsRecorderNoise('dropped'))->toBe(2);
});

it('client reports have a budget of their own: a flood of them never stops a server error being opened', function () {
    foreach (range(1, 21) as $n) {
        errorsRecord(['source' => 'site', 'kind' => 'script', 'platform' => 'web', 'class' => "TypeError{$n}", 'trace' => '']);
    }

    expect(errorsRecord(errorsRecorderOutage()))->toBe(Recorded::Created);

    foreach (range(1, 59) as $n) {
        expect(errorsRecord(['class' => "App\\Exceptions\\Server{$n}"]))->toBe(Recorded::Created);
    }

    expect(errorsRecord(['class' => 'App\\Exceptions\\Server60']))->toBe(Recorded::Dropped)
        ->and(ErrorIssue::query()->where('kind', 'script')->count())->toBe(20)
        ->and(ErrorIssue::query()->whereIn('kind', ['exception', 'http'])->count())->toBe(60);
});

it('client-reported kinds stop at 100 new issues an hour', function () {
    $script = fn (int $n) => ['source' => 'site', 'kind' => 'script', 'platform' => 'web', 'class' => "TypeError{$n}", 'trace' => '', 'status' => null];
    $n = 0;

    foreach (range(0, 5) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')->addMinutes($minute));

        foreach (range(1, $minute < 5 ? 20 : 1) as $ignored) {
            $n++;
            expect(errorsRecord($script($n)))->toBe($n <= 100 ? Recorded::Created : Recorded::Dropped);
        }
    }

    expect($n)->toBe(101) // the 101st came in a minute with 1 so far: the hour's cap held it
        ->and(ErrorIssue::query()->count())->toBe(100)
        ->and(errorsRecord(errorsRecorderOutage()))->toBe(Recorded::Created)
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Server']))->toBe(Recorded::Created);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 11:00:00', 'UTC'));

    expect(errorsRecord($script(102)))->toBe(Recorded::Created);
});

it('error pages below 500 and slow queries have a budget of their own: 30 new issues a minute', function () {
    foreach (range(1, 20) as $n) {
        expect(errorsRecord(errorsRecorderPage($n)))->toBe(Recorded::Created);
    }

    foreach (range(1, 10) as $n) {
        expect(errorsRecord(errorsRecorderSlowQuery($n)))->toBe(Recorded::Created);
    }

    expect(errorsRecord(errorsRecorderPage(21)))->toBe(Recorded::Dropped)
        ->and(errorsRecord(errorsRecorderPage(22, 419)))->toBe(Recorded::Dropped)
        ->and(errorsRecord(errorsRecorderSlowQuery(11)))->toBe(Recorded::Dropped)
        ->and(errorsRecord(errorsRecorderPage(1)))->toBe(Recorded::Counted) // one it knows still counts
        ->and(errorsRecorderNoise('dropped'))->toBe(3);

    $this->travel(1)->minutes();

    expect(errorsRecord(errorsRecorderPage(23)))->toBe(Recorded::Created);
});

it('error pages below 500 and slow queries stop at 200 new issues an hour', function () {
    $n = 0;

    foreach (range(0, 6) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')->addMinutes($minute));

        foreach (range(1, $minute < 6 ? 30 : 21) as $ignored) {
            $n++;
            $quiet = $n % 3 === 0 ? errorsRecorderSlowQuery($n) : errorsRecorderPage($n);
            expect(errorsRecord($quiet))->toBe($n <= 200 ? Recorded::Created : Recorded::Dropped);
        }
    }

    expect($n)->toBe(201) // the 201st came in a minute with 21 so far, under the minute's 30: the hour held it
        ->and(ErrorIssue::query()->count())->toBe(200)
        ->and(errorsRecorderNoise('dropped'))->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-04 11:00:00', 'UTC'));

    expect(errorsRecord(errorsRecorderPage(202)))->toBe(Recorded::Created);
});

it('a flood of new 404s and slow queries up to their limits does not stop a 503 or an exception from opening an issue', function () {
    $n = 0;

    // Strangers asking for 200 different missing pages, and a slow database, in the hour before the real failure.
    foreach (range(0, 6) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC')->addMinutes($minute));

        foreach (range(1, 40) as $ignored) {
            $n++;
            errorsRecord($n % 4 === 0 ? errorsRecorderSlowQuery($n) : errorsRecorderPage($n));
        }
    }

    expect(ErrorIssue::query()->whereIn('kind', ['http', 'slow_query'])->count())->toBe(200)
        ->and(errorsRecord(errorsRecorderPage(999)))->toBe(Recorded::Dropped)
        ->and(errorsRecord(errorsRecorderOutage()))->toBe(Recorded::Created)
        ->and(errorsRecord(['source' => 'site', 'status' => 500] + errorsRecorderOutage('/app/contracts/{contract}')))->toBe(Recorded::Created)
        ->and(errorsRecord(['class' => 'App\\Exceptions\\PaymentFailed']))->toBe(Recorded::Created)
        ->and(errorsRecord(['source' => 'database', 'class' => 'Illuminate\\Database\\QueryException', 'message' => 'SQLSTATE[08006] connection lost']))->toBe(Recorded::Created);
});

it('every new issue draws on one of three budgets, by whether it can send the instant e-mail', function () {
    expect(ErrorRecorder::budget('site', 'exception', 500))->toBe('alert')
        ->and(ErrorRecorder::budget('api', 'exception', null))->toBe('alert')
        ->and(ErrorRecorder::budget('database', 'exception', null))->toBe('alert')
        ->and(ErrorRecorder::budget('api', 'http', 503))->toBe('alert')
        ->and(ErrorRecorder::budget('site', 'http', 500))->toBe('alert')
        ->and(ErrorRecorder::budget('site', 'http', 499))->toBe('quiet')
        ->and(ErrorRecorder::budget('site', 'http', 404))->toBe('quiet')
        ->and(ErrorRecorder::budget('site', 'http', null))->toBe('quiet')
        ->and(ErrorRecorder::budget('database', 'slow_query', null))->toBe('quiet')
        ->and(ErrorRecorder::budget('site', 'script', null))->toBe('client')
        ->and(ErrorRecorder::budget('app', 'crash', null))->toBe('client')
        ->and(ErrorRecorder::budget('app', 'exception', 500))->toBe('client') // whatever a phone sends is a phone's
        ->and(ErrorRecorder::BUDGETS)->toBe([
            'alert' => ['minute' => 60, 'hour' => 500],
            'quiet' => ['minute' => 30, 'hour' => 200],
            'client' => ['minute' => 20, 'hour' => 100],
        ]);
});

it('an existing issue still counts when new-issue caps are reached', function () {
    errorsRecord(['class' => 'App\\Exceptions\\Known']);

    foreach (range(1, 59) as $n) {
        errorsRecord(['class' => "App\\Exceptions\\Flood{$n}"]);
    }

    expect(errorsRecord(['class' => 'App\\Exceptions\\Unknown']))->toBe(Recorded::Dropped)
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Known']))->toBe(Recorded::Counted)
        ->and(ErrorIssue::query()->where('class', 'App\\Exceptions\\Known')->sole()->occurrences)->toBe(2);
});

it('a cache that fails keeps the gate shut for new issues and still counts the ones it knows', function () {
    errorsRecord(['class' => 'App\\Exceptions\\Known']);

    Cache::shouldReceive('add')->andThrow(new RuntimeException('The cache is away.'));

    expect(errorsRecord(['class' => 'App\\Exceptions\\Unknown']))->toBe(Recorded::Dropped)
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Known']))->toBe(Recorded::Counted)
        ->and(ErrorIssue::query()->count())->toBe(1)
        ->and(errorsRecorderNoise('dropped'))->toBe(1);
});

it('a counter that cannot be counted keeps the gate shut too', function () {
    Cache::shouldReceive('add')->andReturn(true);
    Cache::shouldReceive('increment')->andReturn(false); // the key vanished between the two calls

    expect(errorsRecord())->toBe(Recorded::Dropped)
        ->and(ErrorIssue::query()->count())->toBe(0);
});

it('two requests opening the same issue at once: the second one is counted on the first', function () {
    Event::fake([ErrorIssueCreated::class]);
    $event = errorsRecorderEvent();
    $fingerprint = ErrorFingerprint::of(ErrorScrubber::event($event));
    $raced = false;

    // The other request looked a moment ago too, found nothing, and opened the issue just before this one does: the
    // unique fingerprint refuses the second insert.
    DB::listen(function (QueryExecuted $query) use (&$raced, $fingerprint): void {
        if ($raced || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! in_array($fingerprint, $query->bindings, true)) {
            return;
        }

        $raced = true;
        ErrorIssue::query()->forceCreate([
            'fingerprint' => $fingerprint, 'source' => 'site', 'kind' => 'exception', 'title' => 'RuntimeException: Something broke',
            'class' => 'RuntimeException', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    });

    expect(app(ErrorRecorder::class)->record($event))->toBe(Recorded::Counted)
        ->and($raced)->toBeTrue();

    $issue = ErrorIssue::query()->sole();

    expect($issue->occurrences)->toBe(2) // the other request's sighting and this one: nothing is lost
        ->and($issue->samples()->count())->toBe(1)
        ->and(errorsRecorderDays($issue))->toBe(['2026-11-04' => 1])
        ->and(DB::table('error_issues')->count())->toBe(1); // and the connection is still usable after the refused insert
    Event::assertNotDispatched(ErrorIssueCreated::class);
})->group('db-errors'); // the database refuses an insert (inside a savepoint): left out on the PGlite stand-in

it('a request that loses the race to open an issue gives its place in the budget back', function () {
    foreach (range(1, 58) as $n) {
        errorsRecord(['class' => "App\\Exceptions\\Flood{$n}"]); // 58 of the minute's 60
    }

    $event = errorsRecorderEvent();
    $fingerprint = ErrorFingerprint::of(ErrorScrubber::event($event));
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, $fingerprint): void {
        if ($raced || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! in_array($fingerprint, $query->bindings, true)) {
            return;
        }

        $raced = true;
        ErrorIssue::query()->forceCreate([
            'fingerprint' => $fingerprint, 'source' => 'site', 'kind' => 'exception', 'title' => 'RuntimeException: Something broke',
            'class' => 'RuntimeException', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    });

    expect(app(ErrorRecorder::class)->record($event))->toBe(Recorded::Counted)
        // the lost race took nothing from the budget: two more new issues fit in this minute, and only two
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Flood59']))->toBe(Recorded::Created)
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Flood60']))->toBe(Recorded::Created)
        ->and(errorsRecord(['class' => 'App\\Exceptions\\Flood61']))->toBe(Recorded::Dropped);
})->group('db-errors');

// ---- off -----------------------------------------------------------------------------------------------------------

it('QISTAS_ERRORS=false writes nothing and returns Off', function () {
    config(['qistas.errors.enabled' => false]);
    Event::fake([ErrorIssueCreated::class]);

    expect(errorsRecord(['userId' => (string) Str::uuid()]))->toBe(Recorded::Off)
        ->and(errorsRecord(['kind' => 'script', 'source' => 'site', 'platform' => 'web']))->toBe(Recorded::Off);

    app(ErrorRecorder::class)->noise('scanner');
    app(ErrorRecorder::class)->noise('dropped');

    foreach (['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} was written");
    }

    expect(file_exists($this->spool))->toBeFalse()
        ->and(is_dir(dirname($this->spool)))->toBeFalse();
    Event::assertNothingDispatched();
});

// ---- versions and times ------------------------------------------------------------------------------------------

it('first and last version: the version of the first sighting and the highest version seen', function () {
    errorsRecord(['version' => '1.9.0']);
    $this->travel(1)->minutes();
    errorsRecord(['version' => '1.10.0']); // higher, though "1.10.0" sorts before "1.9.0" as text
    $this->travel(1)->minutes();
    errorsRecord(['version' => '1.8.2']); // an old phone still on 1.8.2, seen later: neither first nor newest
    $this->travel(1)->minutes();
    errorsRecord(['version' => null]); // no version: changes neither

    $issue = ErrorIssue::query()->sole();

    expect([$issue->first_version, $issue->last_version, $issue->occurrences])->toBe(['1.9.0', '1.10.0', 4]);
});

it('a sighting from before the first one moves first seen and its version back', function () {
    errorsRecord(['version' => '1.30.0']);

    // Read back from the spool: it happened an hour and a half earlier, while the database was away.
    errorsRecord(['version' => '1.29.0', 'at' => '2026-11-04T09:00:00+00:00']);

    $issue = ErrorIssue::query()->sole();

    expect($issue->first_seen_at->toDateTimeString())->toBe('2026-11-04 09:00:00')
        ->and($issue->last_seen_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and([$issue->first_version, $issue->last_version])->toBe(['1.29.0', '1.30.0']);
});

it('an issue first seen without a version takes the first version it is seen with', function () {
    errorsRecord(['version' => null]);
    $this->travel(1)->minutes();
    errorsRecord(['version' => '1.30.0']);

    $issue = ErrorIssue::query()->sole();

    expect([$issue->first_version, $issue->last_version])->toBe(['1.30.0', '1.30.0']);
});

it('a sighting is placed on the day and at the time of the application clock', function () {
    // 01:30 in a +05:00 zone is 20:30 the evening before in UTC, the application's zone.
    errorsRecord(['at' => '2026-11-04T01:30:00+05:00']);

    $issue = ErrorIssue::query()->sole();

    expect($issue->first_seen_at->toDateTimeString())->toBe('2026-11-03 20:30:00')
        ->and($issue->samples()->sole()->occurred_at->toDateTimeString())->toBe('2026-11-03 20:30:00')
        ->and(errorsRecorderDays($issue))->toBe(['2026-11-03' => 1]);
});

it('a time in the future is taken as now', function () {
    errorsRecord(['at' => '2026-11-09T10:30:00+00:00']);

    $issue = ErrorIssue::query()->sole();

    expect($issue->last_seen_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and($issue->samples()->sole()->occurred_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and(errorsRecorderDays($issue))->toBe(['2026-11-04' => 1]);
});

it('a time from before the occurrences are kept is taken as the oldest time kept', function () {
    errorsRecord(['class' => 'AlphaException', 'at' => '1970-01-01T00:00:00+00:00']);

    config(['qistas.errors.occurrence_days' => 7]);
    errorsRecord(['class' => 'BravoException', 'at' => '0001-01-01T00:00:00+00:00']);

    config(['qistas.errors.occurrence_days' => 0]); // not a usable setting: the default 30 days
    errorsRecord(['class' => 'CharlieException', 'at' => '2026-01-01T00:00:00+00:00']);

    $first = fn (string $class) => ErrorIssue::query()->where('class', $class)->sole()->first_seen_at->toDateTimeString();

    expect($first('AlphaException'))->toBe('2026-10-05 10:30:00') // 30 days before now
        ->and($first('BravoException'))->toBe('2026-10-28 10:30:00') // 7 days
        ->and($first('CharlieException'))->toBe('2026-10-05 10:30:00')
        ->and(ErrorOccurrence::query()->min('occurred_at'))->toStartWith('2026-10-05 10:30:00')
        ->and(errorsRecorderDays(ErrorIssue::query()->where('class', 'BravoException')->sole()))->toBe(['2026-10-28' => 1]);
});

// ---- privacy -------------------------------------------------------------------------------------------------------

it('a stored occurrence holds no personal data', function () {
    $userId = (string) Str::uuid();
    $token = '7|Xq7Lm2Pz9Rt4Vw8Ny3Bk6Hd1Fs5Gj0Ca2Ee4Ii6Oo';
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXZhbHVlLWhlcmU';

    errorsRecord([
        'class' => 'App\\Exceptions\\PaymentFailed',
        'message' => "Card declined for sara.ali@example.com, phone +966 50 123 4567, national id 1098765432, token {$token}",
        'trace' => "#0 /var/www/qistas/web/app/Actions/Pay.php(48): App\\Actions\\Pay->handle('sara.ali@example.com', '+966501234567')\n"
            ."#1 {main}\nAuthorization: Bearer {$jwt}",
        'route' => '/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f?phone=0501234567',
        'userId' => $userId,
    ]);

    $stored = errorsRecorderStored();

    foreach (['sara.ali@example.com', '+966 50 123 4567', '501234567', '1098765432', $token, $jwt, $userId, '0b8a7c1e', '/var/www'] as $value) {
        expect($stored)->not->toContain($value);
    }

    $sample = ErrorOccurrence::query()->sole();

    expect($sample->message)->toContain('[email]')->toContain('[phone]')->toContain('[id]')->toContain('[token]')
        ->and($sample->trace)->toContain('app/Actions/Pay.php(48)')->toContain('[secret]')
        ->and($sample->route)->toBe('/app/customers/{id}')
        ->and(DB::table('error_issue_people')->pluck('person')->all())->toBe([errorsRecorderPerson($userId)]);
});

// ---- noise ---------------------------------------------------------------------------------------------------------

it('noise() counts per day and kind, and leaves alone what it does not know', function () {
    $recorder = app(ErrorRecorder::class);

    $recorder->noise('scanner');
    $recorder->noise('scanner');
    $recorder->noise('dropped', now()->subDay());
    $recorder->noise('dropped', null, 3);
    $recorder->noise('chatter');
    $recorder->noise('scanner', null, 0);

    $rows = DB::table('error_noise_days')->orderBy('day')->orderBy('kind')->get()
        ->map(fn (object $row) => [substr((string) $row->day, 0, 10), $row->kind, (int) $row->count])->all();

    expect($rows)->toBe([
        ['2026-11-03', 'dropped', 1],
        ['2026-11-04', 'dropped', 3],
        ['2026-11-04', 'scanner', 2],
    ]);
});
