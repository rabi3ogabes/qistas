<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureUnavailable;
use App\Entitlements\LimitReached;
use App\Errors\ErrorCapture;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorSpool;
use App\Errors\Events\ErrorIssueCreated;
use App\Http\ApiException;
use App\Http\Middleware\RecordErrorPages;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
| What becomes an error record on the live site (design section 3), through the real HTTP and console kernels: the
| exception hook in bootstrap/app.php, the RecordErrorPages middleware and the slow-query listener of
| ErrorServiceProvider. Every request here is a real one, answered by test routes; nothing of the capture layer is mocked.
| What a stored issue holds once the recorder has it is ErrorRecorderTest.
*/

beforeEach(function () {
    $this->spool = errorsCaptureSpool();
    ErrorSpool::forgetLost(ErrorSpool::lost());

    // The production shape of the answers (the debug pages differ from one request to the next), and no log file.
    config(['app.debug' => false, 'logging.default' => 'null']);

    errorsCaptureRoutes();
});

afterEach(function () {
    errorsCaptureForget($this->spool);
    ErrorSpool::forgetLost(ErrorSpool::lost());
});

/** The routes the tests ask for, on the website and under the API. */
function errorsCaptureRoutes(): void
{
    Route::middleware('web')->group(function (): void {
        Route::get('/errors-test/boom', fn () => throw new RuntimeException('Boom'));
        Route::get('/errors-test/customers/{customer}/boom', fn () => throw new RuntimeException('Boom'));
        Route::get('/errors-test/customers/{customer}/missing', fn () => abort(404));
        Route::get('/errors-test/server-error', fn () => abort(500));
        Route::get('/errors-test/query', fn () => throw errorsCaptureQueryException());
        Route::get('/errors-test/wrapped-query', fn () => throw new RuntimeException('The page could not be drawn', 0, errorsCaptureQueryException()));
        Route::post('/errors-test/validation', fn () => throw ValidationException::withMessages(['email' => 'Not an e-mail address.']));
        Route::get('/errors-test/sign-in', fn () => throw new AuthenticationException);
        Route::get('/errors-test/switched-off', fn () => throw new FeatureUnavailable(Feature::Customers));
        Route::get('/errors-test/forbidden', fn () => abort(403));
        Route::get('/errors-test/expired', fn () => throw new TokenMismatchException);
        Route::get('/errors-test/too-many', fn () => throw new ThrottleRequestsException);
        Route::get('/errors-test/unavailable', fn () => abort(503));
        Route::get('/errors-test/gone', fn () => abort(410));
        Route::get('/errors-test/only-get', fn () => 'ok');

        Route::get('/errors-test/twice', function () {
            // One failure, reported twice in one request with a different number each time: one fingerprint.
            foreach ([1, 2] as $n) {
                report(new RuntimeException("Payment {$n} failed"));
            }

            return 'ok';
        });

        Route::get('/errors-test/known', function () {
            report(new RuntimeException('Failure known'));

            return 'ok';
        });

        Route::get('/errors-test/many', function () {
            foreach (range(1, 100) as $n) {
                report(new RuntimeException('Failure '.errorsCaptureWord($n)));
            }

            report(new RuntimeException('Failure known')); // the 101st: one more than may wait for the answer

            return 'ok';
        });

        Route::get('/errors-test/slow', function () {
            // A real query that takes some milliseconds, with an e-mail address and a national ID as its bindings.
            DB::select(
                'with recursive c(x) as (select 1 union all select x + 1 from c where x < 200000) select count(*) as n from c where ? <> ?',
                ['sara.ali@example.com', '1098765432'],
            );

            return 'ok';
        });

        Route::get('/errors-test/slow-events', function () {
            errorsCaptureQuery('select "a" from "errors_quick" where "email" = ?', 2999.0);
            errorsCaptureQuery('select "b" from "errors_slow" where "email" = ?', 3000.0);

            return 'ok';
        });

        Route::get('/errors-test/own-slow-queries', function () {
            foreach (['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'] as $table) {
                errorsCaptureQuery('select * from "'.$table.'" where "id" = ?', 9000.0);
            }

            return 'ok';
        });
    });

    Route::middleware('api')->prefix('api/v1/errors-test')->group(function (): void {
        Route::get('/boom', fn () => throw new RuntimeException('Boom'));
        Route::get('/unavailable', fn () => abort(503));
        Route::get('/sign-in', fn () => throw new AuthenticationException);
        Route::get('/limit', fn () => throw new LimitReached(Feature::Customers, 5, 5));
        Route::post('/validation', fn () => throw ValidationException::withMessages(['email' => 'Not an e-mail address.']));
        Route::get('/conflict', fn () => throw new ApiException('already_done', 'That was done already.', 409));
        Route::get('/lost-connection', fn () => throw errorsCaptureLostConnection());
        Route::get('/http-client', fn () => throw new ConnectionException('cURL error 56: Recv failure: Connection reset by peer'));
    });
}

/**
 * A failed insert the way PostgreSQL reports it: the values are in the bindings, in Laravel's message (bindings not
 * masked) and in the driver's DETAIL line.
 */
function errorsCaptureQueryException(): QueryException
{
    $detail = 'ERROR:  duplicate key value violates unique constraint "customers_email_unique"'
        ."\nDETAIL:  Key (email, national_id)=(sara.ali@example.com, 1098765432) already exists.";
    $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 '.$detail);
    $pdo->errorInfo = ['23505', 7, $detail];

    return new QueryException('pgsql', 'insert into "customers" ("email", "national_id") values (?, ?)', ['sara.ali@example.com', '1098765432'], $pdo);
}

/** The database went away, as PDO says it (with the server's address and the database user). */
function errorsCaptureLostConnection(): PDOException
{
    $pdo = new PDOException('SQLSTATE[08006] [7] server closed the connection unexpectedly (host db.internal.example, user qistas_owner)');
    $pdo->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];

    return $pdo;
}

/** A query the connection says it ran, taking $ms milliseconds. */
function errorsCaptureQuery(string $sql, float $ms): void
{
    event(new QueryExecuted($sql, ['sara.ali@example.com'], $ms, DB::connection()));
}

/** A word for $n, in letters (digits are folded away by the fingerprint, so a number would not make it different). */
function errorsCaptureWord(int $n): string
{
    $word = '';

    do {
        $word = chr(97 + $n % 26).$word;
        $n = intdiv($n, 26);
    } while ($n > 0);

    return $word;
}

/** The spool of this test alone (in the system's temporary folder), so no test ever writes storage/logs. */
function errorsCaptureSpool(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-capture-'.Str::random(12).DIRECTORY_SEPARATOR.'error-spool.ndjson';
    config(['qistas.errors.spool_path' => $path]);

    return $path;
}

function errorsCaptureForget(string $path): void
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

/** Every row of the five tables as one text: all that a stored record holds. */
function errorsCaptureStored(): string
{
    return collect(['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'])
        ->map(fn (string $table) => DB::table($table)->get()->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->implode("\n");
}

function errorsCaptureNothingStored(): void
{
    foreach (['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} was written");
    }
}

function errorsCaptureNoise(string $kind): int
{
    return (int) DB::table('error_noise_days')->where('kind', $kind)->sum('count');
}

/** The issues as "source/kind/class", sorted. */
function errorsCaptureIssues(): array
{
    return ErrorIssue::query()->get()->map(fn (ErrorIssue $issue) => "{$issue->source}/{$issue->kind}/{$issue->class}")->sort()->values()->all();
}

/** A maintenance mode that lives in memory only (the file driver would write storage/framework/down). */
function errorsCaptureDown(): void
{
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return ['except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null, 'secret' => null, 'status' => 503, 'template' => null];
        }
    });
}

// ---- exceptions ----------------------------------------------------------------------------------------------------

it('a route that throws on the website is a site exception with its route pattern, method and status 500', function () {
    $this->get('/errors-test/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f/boom')->assertStatus(500);

    $issue = ErrorIssue::query()->sole(); // the exception only: its 500 page is not a second issue
    $sample = $issue->samples()->sole();

    expect($issue->only(['source', 'kind', 'class', 'title', 'occurrences']))->toBe([
        'source' => 'site', 'kind' => 'exception', 'class' => 'RuntimeException', 'title' => 'RuntimeException: Boom', 'occurrences' => 1,
    ])
        ->and($sample->only(['status', 'method', 'route', 'message', 'version', 'platform', 'device']))->toBe([
            'status' => 500, 'method' => 'GET', 'route' => '/errors-test/customers/{customer}/boom', 'message' => 'Boom',
            'version' => null, 'platform' => 'server', 'device' => null,
        ])
        ->and($sample->trace)->toContain('tests/Feature/Errors/ErrorCaptureTest.php')
        ->and($sample->trace)->not->toContain(str_replace('\\', '/', base_path()))
        ->and(errorsCaptureStored())->not->toContain('0b8a7c1e');
});

it('the same failure under /api is an api exception, and the API still answers in its own shape', function () {
    $this->getJson('/api/v1/errors-test/boom')
        ->assertStatus(500)
        ->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'Something went wrong on our side. Please try again.']]);

    $issue = ErrorIssue::query()->sole();

    expect($issue->only(['source', 'kind', 'class']))->toBe(['source' => 'api', 'kind' => 'exception', 'class' => 'RuntimeException'])
        ->and($issue->samples()->sole()->only(['status', 'method', 'route']))->toBe(['status' => 500, 'method' => 'GET', 'route' => '/api/v1/errors-test/boom']);
});

it('the person signed in is kept only as the 16-character hash', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/errors-test/boom')->assertStatus(500);

    $person = substr(hash_hmac('sha256', (string) $user->getKey(), config('app.key')), 0, 16);

    expect(ErrorIssue::query()->sole()->people)->toBe(1)
        ->and(DB::table('error_issue_people')->pluck('person')->all())->toBe([$person])
        ->and(errorsCaptureStored())->not->toContain((string) $user->getKey())
        ->and(errorsCaptureStored())->not->toContain($user->email);
});

it('a QueryException keeps its SQLSTATE and placeholders, and none of its values', function () {
    $this->get('/errors-test/query')->assertStatus(500);

    $issue = ErrorIssue::query()->sole();
    $sample = $issue->samples()->sole();
    $stored = errorsCaptureStored();

    expect($issue->only(['source', 'kind', 'class']))->toBe(['source' => 'database', 'kind' => 'exception', 'class' => QueryException::class])
        ->and($sample->message)->toBe('SQLSTATE[23505] (Connection: pgsql, SQL: insert into "customers" ("email", "national_id") values (?, ?))')
        ->and($sample->route)->toBe('/errors-test/query');

    foreach (['sara.ali@example.com', '1098765432', 'DETAIL', 'duplicate key'] as $value) {
        expect($stored)->not->toContain($value);
    }
});

it('a database failure is a database exception wherever it hides, and nothing else is', function () {
    $this->get('/errors-test/wrapped-query')->assertStatus(500); // a view or a wrapper around a failed query
    $this->getJson('/api/v1/errors-test/lost-connection')->assertStatus(500);
    $this->getJson('/api/v1/errors-test/http-client')->assertStatus(500); // "reset by peer" from another server is not the database

    expect(errorsCaptureIssues())->toBe([
        'api/exception/'.ConnectionException::class,
        'database/exception/'.QueryException::class,
        'database/exception/PDOException',
    ]);

    $lost = ErrorIssue::query()->where('class', 'PDOException')->sole()->samples()->sole();
    $stored = errorsCaptureStored();

    expect($lost->message)->toBe('SQLSTATE[08006] Lost connection to the database')
        ->and($lost->route)->toBe('/api/v1/errors-test/lost-connection');

    foreach (['sara.ali@example.com', '1098765432', 'db.internal.example', 'qistas_owner', 'The page could not be drawn'] as $value) {
        expect($stored)->not->toContain($value);
    }
});

it('expected answers are never errors: validation, sign-in, plan and switched-off refusals, and the API below 500', function () {
    $this->post('/errors-test/validation')->assertRedirect();
    $this->get('/errors-test/sign-in')->assertRedirect();
    $this->get('/errors-test/switched-off')->assertStatus(403); // the "not available" page of a switched-off feature
    $this->getJson('/api/v1/errors-test/sign-in')->assertStatus(401);
    $this->getJson('/api/v1/errors-test/limit')->assertStatus(402);
    $this->postJson('/api/v1/errors-test/validation')->assertStatus(422);
    $this->getJson('/api/v1/errors-test/conflict')->assertStatus(409)->assertJsonPath('error.code', 'already_done');
    $this->getJson('/api/v1/errors-test/nothing-here')->assertStatus(404);
    $this->get('/errors-test/gone')->assertStatus(410); // a website status that is not an error page of the list
    $this->post('/errors-test/only-get')->assertStatus(405);

    errorsCaptureNothingStored();
});

it('the same fingerprint reported twice in one request is counted once, and again in the next request', function () {
    $this->get('/errors-test/twice')->assertOk();

    $issue = ErrorIssue::query()->sole();

    expect($issue->occurrences)->toBe(1)
        ->and($issue->samples()->sole()->message)->toBe('Payment 1 failed');

    $this->get('/errors-test/twice')->assertOk();

    expect($issue->fresh()->occurrences)->toBe(2);
});

it('a request that reports more failures than may wait for the answer counts the rest as dropped', function () {
    $this->get('/errors-test/known')->assertOk();
    $known = ErrorIssue::query()->sole();

    $this->get('/errors-test/many')->assertOk();

    // 100 wait for the answer and the 101st (a known failure) is only counted as dropped; of the 100, the recorder opens
    // 59 (its 60 a minute, one taken by the known one) and drops 41.
    expect($known->fresh()->occurrences)->toBe(1)
        ->and(ErrorIssue::query()->count())->toBe(60)
        ->and(errorsCaptureNoise('dropped'))->toBe(42);
});

// ---- error pages ---------------------------------------------------------------------------------------------------

it('a 404 for a path no route serves is one site http issue, and a second visit counts it', function () {
    $this->get('/pricing-typo')->assertNotFound();
    $this->get('/pricing-typo?ref=newsletter')->assertNotFound();

    $issue = ErrorIssue::query()->sole();

    expect($issue->only(['source', 'kind', 'class', 'title', 'occurrences']))->toBe([
        'source' => 'site', 'kind' => 'http', 'class' => 'http.404', 'title' => '404 Not Found: /pricing-typo', 'occurrences' => 2,
    ])
        ->and($issue->samples()->pluck('route')->unique()->all())->toBe(['/pricing-typo'])
        ->and($issue->samples()->first()->only(['status', 'method', 'message', 'trace']))->toBe(['status' => 404, 'method' => 'GET', 'message' => '', 'trace' => '']);
});

it('404s for paths no route serves are grouped by the path with its numbers replaced', function () {
    $this->get('/errors-missing/123/receipt')->assertNotFound();
    $this->get('/errors-missing/98765/receipt')->assertNotFound();

    expect(ErrorIssue::query()->sole()->only(['title', 'occurrences']))->toBe(['title' => '404 Not Found: /errors-missing/{n}/receipt', 'occurrences' => 2]);
});

it('a 404 on a route that exists is grouped by the route\'s pattern', function () {
    $this->get('/errors-test/customers/1/missing')->assertNotFound();
    $this->get('/errors-test/customers/2/missing')->assertNotFound();

    $issue = ErrorIssue::query()->sole();

    expect($issue->title)->toBe('404 Not Found: /errors-test/customers/{customer}/missing')
        ->and($issue->occurrences)->toBe(2);
});

it('paths only a scanner asks for count as scanner noise and never become an issue', function () {
    foreach (['/.env', '/wp-login.php', '/x.sql', '/.env'] as $path) {
        $this->get($path)->assertNotFound();
    }

    expect(ErrorIssue::query()->count())->toBe(0)
        ->and(ErrorOccurrence::query()->count())->toBe(0)
        ->and(errorsCaptureNoise('scanner'))->toBe(4)
        ->and(errorsCaptureNoise('dropped'))->toBe(0);
});

it('a 500 from a reported exception is one issue, and a 500 page with no exception is an http issue', function () {
    $this->get('/errors-test/boom')->assertStatus(500);

    expect(errorsCaptureIssues())->toBe(['site/exception/RuntimeException']);

    $this->get('/errors-test/server-error')->assertStatus(500);

    expect(errorsCaptureIssues())->toBe(['site/exception/RuntimeException', 'site/http/http.500'])
        ->and(ErrorIssue::query()->where('kind', 'http')->sole()->title)->toBe('500 Server Error: /errors-test/server-error');
});

it('the website\'s error pages of 403, 419, 429 and 503 are site http issues', function () {
    $this->get('/errors-test/forbidden')->assertForbidden();
    $this->get('/errors-test/expired')->assertStatus(419);
    $this->get('/errors-test/too-many')->assertStatus(429);
    $this->get('/errors-test/unavailable')->assertStatus(503);

    expect(errorsCaptureIssues())->toBe(['site/http/http.403', 'site/http/http.419', 'site/http/http.429', 'site/http/http.503'])
        ->and(ErrorIssue::query()->where('class', 'http.419')->sole()->title)->toBe('419 Page Expired: /errors-test/expired');
});

it('an API answer of 503 is an api http issue', function () {
    $this->getJson('/api/v1/errors-test/unavailable')->assertStatus(503);

    $issue = ErrorIssue::query()->sole();

    expect($issue->only(['source', 'kind', 'class', 'title']))->toBe([
        'source' => 'api', 'kind' => 'http', 'class' => 'http.503', 'title' => '503 Service Unavailable: /api/v1/errors-test/unavailable',
    ]);
});

it('a 5xx on a path no route serves is one issue, whatever the path', function () {
    // Something before the router answers 500 for these paths (as a failing proxy or a broken middleware would).
    app()->instance('errors-test.answers-500', new class
    {
        public function handle($request, Closure $next)
        {
            return str_contains($request->getPathInfo(), '/errors-unmatched/') ? response('Down', 500) : $next($request);
        }
    });
    app(HttpKernel::class)->pushMiddleware('errors-test.answers-500');

    $this->get('/errors-unmatched/one')->assertStatus(500);
    $this->get('/errors-unmatched/two/2026')->assertStatus(500);
    $this->get('/errors-unmatched/'.Str::random(30))->assertStatus(500);

    $issue = ErrorIssue::query()->sole();

    expect($issue->only(['source', 'class', 'title', 'occurrences']))->toBe([
        'source' => 'site', 'class' => 'http.500', 'title' => '500 Server Error: /{unmatched}', 'occurrences' => 3,
    ])
        ->and($issue->samples()->pluck('route')->unique()->all())->toBe(['/{unmatched}']);

    $this->getJson('/api/v1/errors-unmatched/one')->assertStatus(500);
    $this->getJson('/api/v1/errors-unmatched/two')->assertStatus(500);

    expect(ErrorIssue::query()->where('source', 'api')->sole()->only(['title', 'occurrences']))->toBe(['title' => '500 Server Error: /{unmatched}', 'occurrences' => 2]);
});

it('maintenance mode: a 503 for every path makes no issue', function () {
    errorsCaptureDown();

    foreach (['/', '/pricing', '/errors-test/boom', '/a/b/c', '/'.Str::random(20)] as $path) {
        $this->get($path)->assertStatus(503);
    }

    $this->getJson('/api/v1/errors-test/boom')->assertStatus(503);

    errorsCaptureNothingStored();
});

it('RecordErrorPages ends before the deferred work runs, so what it captures is recorded after the answer', function () {
    $global = app(HttpKernel::class)->getGlobalMiddleware();

    expect(array_search(RecordErrorPages::class, $global, true))->toBeInt()
        ->and(array_search(RecordErrorPages::class, $global, true))->toBeLessThan(array_search(InvokeDeferredCallbacks::class, $global, true));
});

// ---- slow queries --------------------------------------------------------------------------------------------------

it('a slow query is recorded with its placeholders and never its values', function () {
    config(['qistas.errors.slow_query_ms' => 1]);

    $this->get('/errors-test/slow')->assertOk();

    $issue = ErrorIssue::query()->where('kind', 'slow_query')->get()->first(fn (ErrorIssue $found) => str_contains($found->title, 'recursive'));
    $sample = $issue->samples()->sole();
    $stored = errorsCaptureStored();

    expect($issue->only(['source', 'kind', 'class']))->toBe(['source' => 'database', 'kind' => 'slow_query', 'class' => null])
        // The numbers written into the statement are masked too: a raw statement can hold values the same way.
        ->and($sample->message)->toBe('with recursive ?(?) as (select ? union all select ? + ? from c where ? < ?) select count(*) as n from c where ? <> ?')
        ->and($sample->only(['status', 'method', 'route']))->toBe(['status' => null, 'method' => 'GET', 'route' => '/errors-test/slow']);

    foreach (['sara.ali@example.com', '1098765432', '200000'] as $value) {
        expect($stored)->not->toContain($value);
    }
});

it('a slow-query threshold under 1 ms (an empty or mistyped setting) is the default 3000', function (mixed $setting) {
    config(['qistas.errors.slow_query_ms' => $setting]);

    $this->get('/errors-test/slow-events')->assertOk();

    expect(ErrorIssue::query()->sole()->title)->toContain('errors_slow')
        ->and(errorsCaptureStored())->not->toContain('sara.ali@example.com');
})->with([0, '', 'abc', 0.5, -5]);

it('the error center never records its own slow queries, nor one made while it is at work', function () {
    // The default threshold (3000 ms): only the queries this test says took 9 seconds are slow.
    $this->get('/errors-test/own-slow-queries')->assertOk();

    expect(ErrorIssue::query()->count())->toBe(0);

    // A listener of a new issue (the alert mail of Task 6) runs a slow query on an ordinary table while the issue opens.
    Event::listen(ErrorIssueCreated::class, function (): void {
        errorsCaptureQuery('select * from "users" where "email" = ?', 9000.0);
    });

    $this->get('/errors-test/boom')->assertStatus(500);

    expect(errorsCaptureIssues())->toBe(['site/exception/RuntimeException']);
});

// ---- never breaking, never looping, never on when switched off -----------------------------------------------------

it('the answer is the same when the recorder fails', function () {
    config(['qistas.errors.enabled' => false]);
    $api = $this->getJson('/api/v1/errors-test/boom');
    $page = $this->get('/pricing-typo');

    config(['qistas.errors.enabled' => true]);
    $this->app->bind(ErrorRecorder::class, fn () => throw new RuntimeException('The recorder broke'));

    $this->getJson('/api/v1/errors-test/boom')->assertStatus($api->status())->assertExactJson($api->json());
    $this->get('/pricing-typo')->assertStatus($page->status());

    errorsCaptureNothingStored();
});

it('a failure inside the recorder is never reported, so it can never become an issue of its own', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged): void {
        $logged[] = $entry->message;
    });

    // The first recorder made breaks; any made after it is a real one, which would store whatever was reported.
    $made = 0;
    $this->app->bind(ErrorRecorder::class, function ($app) use (&$made) {
        if ($made++ === 0) {
            throw new RuntimeException('The recorder broke');
        }

        return new ErrorRecorder($app->make(ErrorSpool::class));
    });

    $this->get('/errors-test/boom')->assertStatus(500);

    expect(ErrorIssue::query()->count())->toBe(0)
        ->and(collect($logged)->filter(fn (string $message) => str_contains($message, 'The recorder broke'))->all())->toBe([])
        ->and(collect($logged)->filter(fn (string $message) => str_contains($message, 'Boom'))->count())->toBe(1); // the framework still logs the route's own
});

it('what the capture causes while it records is never captured again', function () {
    $ran = false;

    Route::middleware('web')->get('/errors-test/reports-inside', function () use (&$ran) {
        ErrorCapture::guarded(function () use (&$ran): void {
            $ran = true;

            report(new RuntimeException('Reported while recording')); // the framework still logs it; the capture stands back
        });

        return 'ok';
    });

    $this->get('/errors-test/reports-inside')->assertOk();

    expect($ran)->toBeTrue()
        ->and(ErrorCapture::capturing())->toBeFalse()
        ->and(ErrorIssue::query()->count())->toBe(0);
});

it('QISTAS_ERRORS=false records nothing and leaves nothing for after the answer', function () {
    config(['qistas.errors.enabled' => false, 'qistas.errors.slow_query_ms' => 1]);

    $this->get('/errors-test/boom')->assertStatus(500);
    $this->get('/errors-test/server-error')->assertStatus(500);
    $this->get('/pricing-typo')->assertNotFound();
    $this->get('/.env')->assertNotFound();
    $this->getJson('/api/v1/errors-test/unavailable')->assertStatus(503);
    $this->get('/errors-test/slow')->assertOk();

    errorsCaptureNothingStored();

    $ran = false;
    app(ErrorCapture::class)->exception(new RuntimeException('Not now'));
    ErrorCapture::guarded(function () use (&$ran): void {
        $ran = true;
    });

    expect(count(app(DeferredCallbackCollection::class)))->toBe(0);

    app(ErrorCapture::class)->flush();

    expect($ran)->toBeFalse();
    errorsCaptureNothingStored();
});

// ---- commands ------------------------------------------------------------------------------------------------------

it('an artisan command that throws a QueryException is a database exception with no request', function () {
    $kernel = app(ConsoleKernel::class);
    $kernel->registerCommand(new class extends Command
    {
        protected $signature = 'errors-test:query';

        public function handle(): int
        {
            throw errorsCaptureQueryException();
        }
    });

    // What the artisan binary does: handle (which reports the failure), then terminate.
    $input = new ArrayInput(['command' => 'errors-test:query']);
    $status = $kernel->handle($input, new BufferedOutput);
    $kernel->terminate($input, $status);

    $issue = ErrorIssue::query()->sole();

    expect($status)->toBe(1)
        ->and($issue->only(['source', 'kind', 'class']))->toBe(['source' => 'database', 'kind' => 'exception', 'class' => QueryException::class])
        ->and($issue->samples()->sole()->only(['status', 'method', 'route', 'message']))->toBe([
            'status' => null, 'method' => null, 'route' => null,
            'message' => 'SQLSTATE[23505] (Connection: pgsql, SQL: insert into "customers" ("email", "national_id") values (?, ?))',
        ])
        ->and(errorsCaptureStored())->not->toContain('sara.ali@example.com');
});
