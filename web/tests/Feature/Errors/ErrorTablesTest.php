<?php

use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** The columns of spec 5, table by table. */
function errorsTableColumns(): array
{
    return [
        'error_issues' => [
            'id', 'fingerprint', 'source', 'kind', 'title', 'class', 'status', 'occurrences', 'people',
            'first_seen_at', 'last_seen_at', 'first_version', 'last_version', 'resolved_at', 'resolved_by',
            'ignored_at', 'ignored_by', 'reopened_at', 'reopened_version', 'alerted_at', 'created_at', 'updated_at',
        ],
        'error_occurrences' => [
            'id', 'issue_id', 'occurred_at', 'status', 'method', 'route', 'message', 'trace', 'version', 'platform',
            'device', 'created_at',
        ],
        'error_issue_days' => ['issue_id', 'day', 'count'],
        'error_issue_people' => ['issue_id', 'person'],
        'error_noise_days' => ['day', 'kind', 'count'],
    ];
}

/** An issue straight into the table, the way the recorder will write one (status and counters are not mass assignable). */
function errorsIssue(array $attributes = []): ErrorIssue
{
    return ErrorIssue::query()->forceCreate($attributes + [
        'fingerprint' => sha1(Str::random(32)),
        'source' => 'site',
        'kind' => 'exception',
        'title' => 'RuntimeException: something broke',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

it('the five tables exist with the columns of the spec', function () {
    foreach (errorsTableColumns() as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is missing");

        $actual = Schema::getColumnListing($table);
        sort($actual);
        sort($columns);

        expect($actual)->toBe($columns);
    }
});

it('the columns the spec marks null are nullable and the others are not', function () {
    // created_at and updated_at are left out: timestamps() makes them nullable by Laravel's convention.
    $nullable = fn (string $table): array => collect(Schema::getColumns($table))
        ->filter(fn (array $column) => $column['nullable'] && ! in_array($column['name'], ['created_at', 'updated_at'], true))
        ->pluck('name')->sort()->values()->all();

    expect($nullable('error_issues'))->toBe([
        'alerted_at', 'class', 'first_version', 'ignored_at', 'ignored_by', 'last_version', 'reopened_at',
        'reopened_version', 'resolved_at', 'resolved_by',
    ])
        ->and($nullable('error_occurrences'))->toBe(['device', 'method', 'route', 'status', 'version'])
        ->and($nullable('error_issue_days'))->toBe([])
        ->and($nullable('error_issue_people'))->toBe([])
        ->and($nullable('error_noise_days'))->toBe([]);
});

it('no error table has a tenant_id', function () {
    foreach (array_keys(errorsTableColumns()) as $table) {
        expect(Schema::hasColumn($table, 'tenant_id'))->toBeFalse("{$table} must not be tenant scoped");
    }

    foreach ([ErrorIssue::class, ErrorOccurrence::class] as $model) {
        expect(class_uses_recursive($model))->not->toContain(BelongsToTenant::class);
    }
});

it('no error table can hold an address, a user agent, a cookie, a request body or a person', function () {
    $never = ['ip', 'ip_address', 'user_agent', 'cookie', 'cookies', 'body', 'request', 'headers', 'user_id', 'tenant_name', 'business', 'email', 'phone'];

    foreach (array_keys(errorsTableColumns()) as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is missing")
            ->and(array_values(array_intersect(Schema::getColumnListing($table), $never)))->toBe([], "{$table} stores something the spec forbids");
    }
});

it('the fingerprint is unique', function () {
    $fingerprint = sha1('site|exception|RuntimeException|boom|app/A.php::run');

    errorsIssue(['fingerprint' => $fingerprint]);

    expect(fn () => errorsIssue(['fingerprint' => $fingerprint]))->toThrow(UniqueConstraintViolationException::class)
        ->and(ErrorIssue::query()->count())->toBe(1);

    errorsIssue(['fingerprint' => sha1('another')]);

    expect(ErrorIssue::query()->count())->toBe(2);
});

it('issues are indexed by status and last seen and by source and status', function () {
    $indexes = collect(Schema::getIndexes('error_issues'));

    expect($indexes->contains(fn (array $index) => $index['columns'] === ['status', 'last_seen_at'] && ! $index['unique']))->toBeTrue()
        ->and($indexes->contains(fn (array $index) => $index['columns'] === ['source', 'status'] && ! $index['unique']))->toBeTrue()
        ->and($indexes->contains(fn (array $index) => $index['columns'] === ['fingerprint'] && $index['unique']))->toBeTrue();
});

it('days, people and noise are keyed by their pair', function () {
    $issue = errorsIssue();

    DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => '2026-11-05', 'count' => 3]);
    DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => '2026-11-06', 'count' => 1]);
    expect(fn () => DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => '2026-11-05', 'count' => 9]))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('error_issue_people')->insert(['issue_id' => $issue->id, 'person' => 'a1b2c3d4e5f60718']);
    DB::table('error_issue_people')->insert(['issue_id' => $issue->id, 'person' => '0718a1b2c3d4e5f6']);
    expect(fn () => DB::table('error_issue_people')->insert(['issue_id' => $issue->id, 'person' => 'a1b2c3d4e5f60718']))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('error_noise_days')->insert(['day' => '2026-11-05', 'kind' => 'scanner', 'count' => 40]);
    DB::table('error_noise_days')->insert(['day' => '2026-11-05', 'kind' => 'dropped', 'count' => 2]);
    expect(fn () => DB::table('error_noise_days')->insert(['day' => '2026-11-05', 'kind' => 'scanner', 'count' => 1]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('error_issue_days')->count())->toBe(2)
        ->and(DB::table('error_issue_people')->count())->toBe(2)
        ->and(DB::table('error_noise_days')->count())->toBe(2);
});

it('deleting an issue removes its occurrences, days and people', function () {
    $doomed = errorsIssue();
    $kept = errorsIssue();

    foreach ([$doomed, $kept] as $issue) {
        $issue->samples()->create(['occurred_at' => now(), 'message' => 'boom', 'trace' => '', 'platform' => 'server']);
        DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => '2026-11-05', 'count' => 1]);
        DB::table('error_issue_people')->insert(['issue_id' => $issue->id, 'person' => 'a1b2c3d4e5f60718']);
    }

    $doomed->delete();

    expect(ErrorOccurrence::query()->where('issue_id', $doomed->id)->count())->toBe(0)
        ->and(DB::table('error_issue_days')->where('issue_id', $doomed->id)->count())->toBe(0)
        ->and(DB::table('error_issue_people')->where('issue_id', $doomed->id)->count())->toBe(0)
        ->and(ErrorOccurrence::query()->where('issue_id', $kept->id)->count())->toBe(1)
        ->and(DB::table('error_issue_days')->where('issue_id', $kept->id)->count())->toBe(1)
        ->and(DB::table('error_issue_people')->where('issue_id', $kept->id)->count())->toBe(1);
});

it('an occurrence belongs to its issue and has no updated_at', function () {
    $issue = errorsIssue();

    $occurrence = $issue->samples()->create([
        'occurred_at' => now(), 'status' => 500, 'method' => 'POST', 'route' => '/app/customers/{id}',
        'message' => 'boom', 'trace' => '#0 app/A.php(1): run()', 'version' => '1.30.0', 'platform' => 'server', 'device' => null,
    ])->fresh();

    expect($occurrence->issue->is($issue))->toBeTrue()
        ->and($occurrence->status)->toBe(500)
        ->and($occurrence->occurred_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($occurrence->created_at)->not->toBeNull()
        ->and($occurrence->getAttributes())->not->toHaveKey('updated_at')
        ->and($issue->samples()->count())->toBe(1);
});

it('the occurrences count stays a number when the samples are loaded', function () {
    $issue = errorsIssue();
    $issue->forceFill(['occurrences' => 41])->save();
    $issue->samples()->create(['occurred_at' => now(), 'message' => 'boom', 'trace' => '', 'platform' => 'server']);

    $loaded = ErrorIssue::query()->with('samples')->findOrFail($issue->id);

    expect($loaded->occurrences)->toBe(41)
        ->and($loaded->toArray()['occurrences'])->toBe(41)
        ->and($loaded->samples)->toHaveCount(1);
});

it('samples come newest first', function () {
    $issue = errorsIssue();
    foreach ([now()->subMinutes(2), now()->subMinute(), now()] as $minute => $at) {
        $issue->samples()->create(['occurred_at' => $at, 'message' => "sighting {$minute}", 'trace' => '', 'platform' => 'server']);
    }

    expect($issue->samples()->pluck('message')->all())->toBe(['sighting 2', 'sighting 1', 'sighting 0']);
});

it('status defaults to open and openCount counts only open issues', function () {
    $fresh = errorsIssue();

    expect($fresh->fresh()->status)->toBe('open')
        ->and($fresh->fresh()->occurrences)->toBe(0)
        ->and($fresh->fresh()->people)->toBe(0)
        ->and(ErrorIssue::STATUSES)->toBe(['open', 'resolved', 'ignored']);

    errorsIssue();
    errorsIssue(['status' => 'resolved', 'resolved_at' => now()]);
    errorsIssue(['status' => 'ignored', 'ignored_at' => now()]);

    expect(ErrorIssue::openCount())->toBe(2)
        ->and(ErrorIssue::query()->open()->count())->toBe(2)
        ->and(ErrorIssue::query()->open()->pluck('status')->unique()->all())->toBe(['open']);
});

it('the models list #[Fillable] explicitly', function () {
    foreach ([ErrorIssue::class, ErrorOccurrence::class] as $model) {
        expect((new ReflectionClass($model))->getAttributes(Fillable::class))->toHaveCount(1);
    }

    $issue = new ErrorIssue;
    $notFillable = ['id', 'status', 'occurrences', 'people', 'resolved_at', 'resolved_by', 'ignored_at', 'ignored_by', 'reopened_at', 'reopened_version', 'alerted_at', 'created_at', 'updated_at'];

    expect(array_values(array_filter($notFillable, fn (string $column) => $issue->isFillable($column))))->toBe([])
        ->and($issue->getFillable())->toContain('fingerprint', 'source', 'kind', 'title', 'class');

    foreach (['status' => 'resolved', 'occurrences' => 99, 'resolved_by' => (string) Str::uuid()] as $column => $value) {
        expect(fn () => new ErrorIssue([$column => $value]))->toThrow(MassAssignmentException::class);
    }

    $occurrence = new ErrorOccurrence;

    expect(array_values(array_filter(['id', 'created_at', 'updated_at'], fn (string $column) => $occurrence->isFillable($column))))->toBe([])
        ->and($occurrence->getFillable())->toContain('issue_id', 'occurred_at', 'message', 'trace', 'platform');
    expect(fn () => new ErrorOccurrence(['id' => (string) Str::uuid()]))->toThrow(MassAssignmentException::class);
});

it('names the vocabulary of the spec', function () {
    expect(ErrorIssue::SOURCES)->toBe(['site', 'api', 'database', 'app'])
        ->and(ErrorIssue::KINDS)->toBe(['exception', 'http', 'slow_query', 'script', 'crash']);
});

it('config defaults', function () {
    expect(config('qistas.errors.enabled'))->toBeTrue()
        ->and(config('qistas.errors.slow_query_ms'))->toBe(3000)
        ->and(config('qistas.errors.occurrence_days'))->toBe(30)
        ->and(config('qistas.errors.issue_days'))->toBe(90)
        ->and(config('qistas.errors.scanner_paths'))->toBeArray()->not->toBeEmpty();
});

it('the settings read their environment variables', function () {
    $variables = [
        'QISTAS_ERRORS' => 'false', 'QISTAS_SLOW_QUERY_MS' => '1500', 'QISTAS_ERROR_OCCURRENCE_DAYS' => '7', 'QISTAS_ERROR_ISSUE_DAYS' => '14',
    ];

    try {
        foreach ($variables as $name => $value) {
            Env::getRepository()->clear($name);
            Env::getRepository()->set($name, $value);
        }

        $errors = (require config_path('qistas.php'))['errors'];
    } finally {
        foreach (array_keys($variables) as $name) {
            Env::getRepository()->clear($name);
        }
    }

    expect($errors['enabled'])->toBeFalse()
        ->and($errors['slow_query_ms'])->toBe(1500)
        ->and($errors['occurrence_days'])->toBe(7)
        ->and($errors['issue_days'])->toBe(14);
});

it('the scanner paths are valid case-insensitive patterns that catch scanners and spare real pages', function () {
    $patterns = config('qistas.errors.scanner_paths');
    $isScanner = fn (string $path): bool => collect($patterns)->contains(fn (string $pattern) => preg_match($pattern, $path) === 1);

    foreach ($patterns as $pattern) {
        expect(@preg_match($pattern, ''))->not->toBeFalse("{$pattern} is not a valid pattern")
            ->and(preg_match('/[a-z]*$/', $pattern, $flags))->toBe(1)
            ->and($flags[0])->toContain('i');
    }

    $scanners = [
        '/.env', '/.env.local', '/config/.env', '/.git/config', '/app/.git/HEAD', '/wp-login.php', '/wp-admin/', '/wp-includes/js/jquery.js',
        '/xmlrpc.php', '/phpmyadmin/', '/phpmyadmin/index.php', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
        '/admin.php', '/shell.php', '/old/index.php', '/backup.sql', '/db.bak', '/site.zip', '/old/site.zip', '/WP-LOGIN.PHP', '/PhpMyAdmin/',
    ];
    $real = [
        '/', '/pricing', '/ar/pricing', '/help/getting-started', '/login', '/app', '/app/customers', '/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f',
        '/api/v1/contracts', '/admin/errors', '/app/environment', '/blog/zip-codes', '/features/wp',
    ];

    expect(array_values(array_filter($scanners, fn (string $path) => ! $isScanner($path))))->toBe([])
        ->and(array_values(array_filter($real, $isScanner)))->toBe([]);
});
