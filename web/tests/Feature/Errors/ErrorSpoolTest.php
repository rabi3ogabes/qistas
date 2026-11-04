<?php

use App\Errors\ErrorEvent;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorSpool;
use App\Errors\Events\ErrorIssueCreated;
use App\Errors\Recorded;
use App\Models\ErrorIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| When the database is away the recorder writes the already-scrubbed event as one JSON line to the spool
| (storage/logs/error-spool.ndjson, at most 2 MB), and `qistas:errors:import-spool` records the lines once the database
| answers again (design section 8). Here: what a line holds (never a user id, never unscrubbed text), the cap, that a
| failing spool never throws, and how an import keeps what it could not record.
|
| The tests that make the database refuse something (a table renamed away, a trigger) are in the group db-errors: they
| are left out on the PGlite stand-in, and they run on PostgreSQL in CI, so every refusal they cause happens inside the
| recorder's own transactions (a savepoint here), never in the test's.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30:00', 'UTC'));
    $this->spool = errorsSpoolUse();
});

afterEach(function () {
    errorsSpoolForget($this->spool);
});

/** An event the way the capture layer builds one, any field replaced. */
function errorsSpoolEvent(array $with = []): ErrorEvent
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

/** The spool of this test alone, in the system's temporary folder. */
function errorsSpoolUse(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-spool-'.Str::random(12).DIRECTORY_SEPARATOR.'error-spool.ndjson';
    config(['qistas.errors.spool_path' => $path]);

    return $path;
}

function errorsSpoolForget(string $path): void
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

/** @return list<array<string, mixed>> the spool's lines, decoded */
function errorsSpoolLines(string $path): array
{
    clearstatcache();

    if (! is_file($path)) {
        return [];
    }

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), fn (string $line) => $line !== ''));

    return array_map(fn (string $line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
}

function errorsSpoolSize(string $path): int
{
    clearstatcache();

    return is_file($path) ? (int) filesize($path) : 0;
}

/** The database cannot find the issues table: as good as away, for the recorder. */
function errorsSpoolTablesAway(): void
{
    Schema::rename('error_issues', 'error_issues_away');
}

function errorsSpoolTablesBack(): void
{
    Schema::rename('error_issues_away', 'error_issues');
}

/** The database refuses one kind of line (an issue whose title holds "poison") while every table answers. */
function errorsSpoolRefusePoison(): void
{
    if (DB::getDriverName() === 'pgsql') {
        DB::unprepared(<<<'SQL'
            create function errors_spool_refuse() returns trigger language plpgsql as $$
            begin
                if new.title like '%poison%' then
                    raise exception 'refused by the test';
                end if;
                return new;
            end
            $$;
            create trigger errors_spool_refuse before insert on error_issues for each row execute function errors_spool_refuse();
            SQL);

        return;
    }

    DB::unprepared("create trigger errors_spool_refuse before insert on error_issues when new.title like '%poison%' begin select raise(abort, 'refused by the test'); end");
}

function errorsSpoolDropped(): int
{
    return (int) DB::table('error_noise_days')->where('kind', 'dropped')->sum('count');
}

function errorsSpoolImport(?float $seconds = null): int
{
    return app(ErrorSpool::class)->import(app(ErrorRecorder::class), $seconds);
}

// ---- writing the spool ---------------------------------------------------------------------------------------------

it('when the database write throws, the scrubbed event is appended to the spool and Spooled is returned', function () {
    $userId = (string) Str::uuid();

    errorsSpoolTablesAway();
    $result = app(ErrorRecorder::class)->record(errorsSpoolEvent([
        'message' => 'Card declined for sara.ali@example.com',
        'route' => '/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f?phone=0501234567',
        'userId' => $userId,
    ]));
    errorsSpoolTablesBack();

    $raw = (string) file_get_contents($this->spool);
    $lines = errorsSpoolLines($this->spool);

    expect($result)->toBe(Recorded::Spooled)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray([
            'source' => 'site', 'kind' => 'exception', 'class' => 'RuntimeException', 'status' => 500, 'method' => 'POST',
            'route' => '/app/customers/{id}', 'version' => '1.30.0', 'platform' => 'server',
            'user_id' => 'h:'.substr(hash_hmac('sha256', $userId, config('app.key')), 0, 16),
            'at' => '2026-11-04T10:30:00+00:00',
        ])
        ->and($lines[0]['message'])->toContain('[email]')
        ->and($raw)->not->toContain('sara.ali@example.com')->not->toContain($userId)->not->toContain('0501234567')
        ->and(substr_count($raw, "\n"))->toBe(1)
        ->and(ErrorIssue::query()->count())->toBe(0);
})->group('db-errors');

it('inside a caller\'s transaction, a refused write is spooled and the caller\'s transaction goes on', function () {
    $result = DB::transaction(function (): Recorded {
        errorsSpoolTablesAway();
        $result = app(ErrorRecorder::class)->record(errorsSpoolEvent());
        errorsSpoolTablesBack();

        // On PostgreSQL a refused statement outside a savepoint would leave this transaction failed: every next query
        // would be refused too. The recorder's own transaction is a savepoint here, so the caller carries on.
        DB::table('error_noise_days')->insert(['day' => '2026-11-04', 'kind' => 'scanner', 'count' => 1]);

        return $result;
    });

    expect($result)->toBe(Recorded::Spooled)
        ->and(errorsSpoolLines($this->spool))->toHaveCount(1)
        ->and(DB::table('error_noise_days')->count())->toBe(1);
})->group('db-errors');

it('append() scrubs what it is given and writes the person hash, never the user id', function () {
    $userId = (string) Str::uuid();

    expect(app(ErrorSpool::class)->append(errorsSpoolEvent([
        'message' => 'Login failed for sara.ali@example.com with password=hunter2',
        'trace' => "#0 /var/www/qistas/web/app/Auth/Login.php(12): App\\Auth\\Login->check('sara.ali@example.com')",
        'userId' => $userId,
    ])))->toBeTrue();

    $raw = (string) file_get_contents($this->spool);
    $line = errorsSpoolLines($this->spool)[0];

    expect($raw)->not->toContain('sara.ali@example.com')->not->toContain('hunter2')->not->toContain($userId)->not->toContain('/var/www')
        ->and($line['user_id'])->toBe('h:'.substr(hash_hmac('sha256', $userId, config('app.key')), 0, 16))
        ->and($line['trace'])->toContain('app/Auth/Login.php(12)')
        ->and(ErrorEvent::fromArray($line)->userId)->toBe($line['user_id']); // the form the recorder reads back
});

it('an imported event counts the same person as one recorded directly', function () {
    $userId = (string) Str::uuid();
    $recorder = app(ErrorRecorder::class);

    expect($recorder->record(errorsSpoolEvent(['userId' => $userId])))->toBe(Recorded::Created);

    errorsSpoolTablesAway();
    expect($recorder->record(errorsSpoolEvent(['userId' => $userId])))->toBe(Recorded::Spooled);
    errorsSpoolTablesBack();

    expect(errorsSpoolImport())->toBe(1);

    $issue = ErrorIssue::query()->sole();

    expect($issue->occurrences)->toBe(2)
        ->and($issue->people)->toBe(1)
        ->and(DB::table('error_issue_people')->pluck('person')->all())->toBe([substr(hash_hmac('sha256', $userId, config('app.key')), 0, 16)]);
})->group('db-errors');

it('a failing spool never throws', function () {
    // A file stands where the spool's folder should be: the folder cannot be made and the spool cannot be opened.
    $file = (string) tempnam(sys_get_temp_dir(), 'qistas');
    config(['qistas.errors.spool_path' => $file.DIRECTORY_SEPARATOR.'error-spool.ndjson']);
    $lost = ErrorSpool::lost();

    try {
        expect(app(ErrorSpool::class)->append(errorsSpoolEvent()))->toBeFalse()
            ->and(ErrorSpool::lost())->toBe($lost + 1)
            ->and(errorsSpoolImport())->toBe(0);
    } finally {
        unlink($file);
    }
});

it('a database that is away and a spool that cannot be written: the event is dropped and nothing throws', function () {
    $file = (string) tempnam(sys_get_temp_dir(), 'qistas');
    config(['qistas.errors.spool_path' => $file.DIRECTORY_SEPARATOR.'error-spool.ndjson']);

    try {
        errorsSpoolTablesAway();
        $result = app(ErrorRecorder::class)->record(errorsSpoolEvent());
        errorsSpoolTablesBack();
    } finally {
        unlink($file);
    }

    expect($result)->toBe(Recorded::Dropped)
        ->and(ErrorRecorder::busy())->toBeFalse();
})->group('db-errors');

it('the spool stops growing at 2 MB', function () {
    $spool = app(ErrorSpool::class);
    mkdir(dirname($this->spool), 0777, true);

    // Nearly full: the next line does not fit, so it is not written (not even in part).
    file_put_contents($this->spool, str_repeat('x', ErrorSpool::MAX_BYTES - 100)."\n");
    $lost = ErrorSpool::lost();

    expect(ErrorSpool::MAX_BYTES)->toBe(2 * 1024 * 1024)
        ->and($spool->append(errorsSpoolEvent()))->toBeFalse()
        ->and(errorsSpoolSize($this->spool))->toBe(ErrorSpool::MAX_BYTES - 99)
        ->and(ErrorSpool::lost())->toBe($lost + 1);

    // Some room: lines go in until the next one would not fit, and the file never passes the cap.
    file_put_contents($this->spool, str_repeat('x', ErrorSpool::MAX_BYTES - 5000)."\n");
    $appended = 0;

    while ($spool->append(errorsSpoolEvent())) {
        $appended++;
        expect(errorsSpoolSize($this->spool))->toBeLessThanOrEqual(ErrorSpool::MAX_BYTES);
    }

    expect($appended)->toBeGreaterThan(3)
        ->and(errorsSpoolSize($this->spool))->toBeLessThanOrEqual(ErrorSpool::MAX_BYTES)
        ->and(ErrorSpool::MAX_BYTES - errorsSpoolSize($this->spool))->toBeLessThan(1000); // it filled up to the last line that fit
});

it('the spool lives in storage/logs unless a test points it elsewhere', function () {
    config(['qistas.errors.spool_path' => null]);

    expect(ErrorSpool::path())->toBe(storage_path('logs'.DIRECTORY_SEPARATOR.'error-spool.ndjson'));

    config(['qistas.errors.spool_path' => $this->spool]);

    expect(ErrorSpool::path())->toBe($this->spool);
});

it('with QISTAS_ERRORS=false the spool is neither written nor read', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent());
    $before = (string) file_get_contents($this->spool);

    config(['qistas.errors.enabled' => false]);

    expect($spool->append(errorsSpoolEvent(['class' => 'LogicException'])))->toBeFalse()
        ->and(errorsSpoolImport())->toBe(0)
        ->and((string) file_get_contents($this->spool))->toBe($before)
        ->and(ErrorIssue::query()->count())->toBe(0);

    errorsSpoolForget($this->spool);
    expect($spool->append(errorsSpoolEvent()))->toBeFalse()
        ->and(file_exists($this->spool))->toBeFalse();
});

// ---- importing it --------------------------------------------------------------------------------------------------

it('import() records every line, truncates what it imported and returns the count', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent());
    $this->travel(1)->minutes();
    $spool->append(errorsSpoolEvent());
    $spool->append(errorsSpoolEvent(['class' => 'LogicException', 'version' => '1.29.0']));

    $this->travel(10)->minutes(); // the database answers again

    expect(errorsSpoolImport())->toBe(3)
        ->and(errorsSpoolSize($this->spool))->toBe(0);

    $issues = ErrorIssue::query()->orderBy('class')->get();

    expect($issues->map(fn (ErrorIssue $issue) => [$issue->class, $issue->occurrences])->all())->toBe([['LogicException', 1], ['RuntimeException', 2]])
        // each sighting keeps the time it happened, not the time it was read back
        ->and($issues[1]->first_seen_at->toDateTimeString())->toBe('2026-11-04 10:30:00')
        ->and($issues[1]->last_seen_at->toDateTimeString())->toBe('2026-11-04 10:31:00')
        ->and($issues[0]->first_version)->toBe('1.29.0');

    expect(errorsSpoolImport())->toBe(0); // nothing left: nothing imported twice
});

it('import() stops at the first line the database still refuses and keeps the rest', function () {
    $spool = app(ErrorSpool::class);

    foreach (['AlphaException', 'BravoException', 'CharlieException'] as $class) {
        $spool->append(errorsSpoolEvent(['class' => $class]));
    }

    // The database goes away again right after the first line is in.
    $away = false;
    Event::listen(ErrorIssueCreated::class, function () use (&$away): void {
        if (! $away) {
            $away = true;
            errorsSpoolTablesAway();
        }
    });

    expect(errorsSpoolImport())->toBe(1);
    errorsSpoolTablesBack();

    $left = errorsSpoolLines($this->spool);

    expect(array_column($left, 'class'))->toBe(['BravoException', 'CharlieException'])
        ->and(array_key_exists('tries', $left[0]))->toBeFalse() // the database was away: that counts against no line
        ->and(ErrorIssue::query()->pluck('class')->all())->toBe(['AlphaException']);

    // Once it answers, the rest comes in.
    expect(errorsSpoolImport())->toBe(2)
        ->and(errorsSpoolSize($this->spool))->toBe(0)
        ->and(ErrorIssue::query()->count())->toBe(3);
})->group('db-errors');

it('a line the database refuses while its tables answer is tried three times, then dropped and counted', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent(['message' => 'poison pill']));
    $spool->append(errorsSpoolEvent(['class' => 'LogicException']));
    errorsSpoolRefusePoison();

    expect(errorsSpoolImport())->toBe(0)
        ->and(array_column(errorsSpoolLines($this->spool), 'tries'))->toBe([1])
        ->and(errorsSpoolLines($this->spool))->toHaveCount(2);

    expect(errorsSpoolImport())->toBe(0)
        ->and(array_column(errorsSpoolLines($this->spool), 'tries'))->toBe([2]);

    // The third refusal drops it, so one bad line can never hold back the spool for good, and the line behind it comes in.
    expect(errorsSpoolImport())->toBe(1)
        ->and(errorsSpoolSize($this->spool))->toBe(0)
        ->and(ErrorIssue::query()->pluck('class')->all())->toBe(['LogicException'])
        ->and(errorsSpoolDropped())->toBe(1);
})->group('db-errors');

it('an unreadable or corrupt line is skipped and counted as dropped, not fatal', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent(['class' => 'AlphaException']));
    file_put_contents($this->spool, "not json at all\n".'{"kind":"exception","message":"no source"}'."\n[1,2,3]\n\"a string\"\n\n", FILE_APPEND);
    $spool->append(errorsSpoolEvent(['class' => 'BravoException']));
    file_put_contents($this->spool, '{"source":"site","kind":"excep', FILE_APPEND); // a write cut short by a crash

    expect(errorsSpoolImport())->toBe(2)
        ->and(errorsSpoolSize($this->spool))->toBe(0)
        ->and(ErrorIssue::query()->orderBy('class')->pluck('class')->all())->toBe(['AlphaException', 'BravoException'])
        ->and(errorsSpoolDropped())->toBe(5);
});

it('an import that runs out of time keeps the lines it did not reach, and always gets one line further', function () {
    $spool = app(ErrorSpool::class);

    foreach (['AlphaException', 'BravoException', 'CharlieException'] as $class) {
        $spool->append(errorsSpoolEvent(['class' => $class]));
    }

    expect(errorsSpoolImport(0.0))->toBe(1)
        ->and(array_column(errorsSpoolLines($this->spool), 'class'))->toBe(['BravoException', 'CharlieException'])
        ->and(errorsSpoolImport(0.0))->toBe(1)
        ->and(errorsSpoolImport())->toBe(1)
        ->and(errorsSpoolSize($this->spool))->toBe(0)
        ->and(ErrorIssue::query()->count())->toBe(3);
});

it('lines written while an import is at work are kept', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent(['class' => 'AlphaException']));

    // Another request finds the database away in the middle of the import and spools its own event.
    Event::listen(ErrorIssueCreated::class, function () use ($spool): void {
        $spool->append(errorsSpoolEvent(['class' => 'LateException']));
    });

    expect(errorsSpoolImport())->toBe(1)
        ->and(array_column(errorsSpoolLines($this->spool), 'class'))->toBe(['LateException']);
});

it('a second import while one is at work does nothing', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent());
    $before = (string) file_get_contents($this->spool);

    $other = fopen($this->spool.'.lock', 'c');
    flock($other, LOCK_EX);

    try {
        expect(errorsSpoolImport())->toBe(0)
            ->and((string) file_get_contents($this->spool))->toBe($before);
    } finally {
        flock($other, LOCK_UN);
        fclose($other);
    }

    expect(errorsSpoolImport())->toBe(1);
});

it('an import with no spool file writes nothing', function () {
    expect(errorsSpoolImport())->toBe(0)
        ->and(is_dir(dirname($this->spool)))->toBeFalse();
});

// ---- the command ---------------------------------------------------------------------------------------------------

it('the command qistas:errors:import-spool prints how many it imported and exits 0', function () {
    $spool = app(ErrorSpool::class);
    $spool->append(errorsSpoolEvent());
    $spool->append(errorsSpoolEvent(['class' => 'LogicException']));

    $this->artisan('qistas:errors:import-spool')
        ->expectsOutputToContain('Imported 2 error reports from the spool.')
        ->assertExitCode(0);

    $spool->append(errorsSpoolEvent());

    $this->artisan('qistas:errors:import-spool')
        ->expectsOutputToContain('Imported 1 error report from the spool.')
        ->assertExitCode(0);

    $this->artisan('qistas:errors:import-spool')
        ->expectsOutputToContain('Imported 0 error reports from the spool.')
        ->assertExitCode(0);

    expect(ErrorIssue::query()->count())->toBe(2);
});

it('the command leaves the spool alone while capture is off', function () {
    app(ErrorSpool::class)->append(errorsSpoolEvent());
    config(['qistas.errors.enabled' => false]);

    $this->artisan('qistas:errors:import-spool')
        ->expectsOutputToContain('Error capture is off')
        ->assertExitCode(0);

    expect(errorsSpoolLines($this->spool))->toHaveCount(1)
        ->and(ErrorIssue::query()->count())->toBe(0);
});
