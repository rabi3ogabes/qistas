<?php

use App\Errors\ClientErrorIntake;
use App\Errors\ErrorCapture;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorSpool;
use App\Errors\Events\ErrorIssueCreated;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\GuardClientReport;
use App\Http\Middleware\KeepStaffOutOfTwoFactor;
use App\Http\Middleware\PreventDuplicateSubmissions;
use App\Http\Middleware\ReadOnlyWhileDeleting;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetCurrentTenant;
use App\Http\Middleware\SetLocale;
use App\Models\ErrorIssue;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| The two reporters (design section 9), through the real kernel: the website's scripts post to POST /client-errors, the
| phone app to POST /api/v1/client-errors. Both are untrusted: any visitor can send to them. So what these tests pin is what
| a stranger can and cannot do: the size, type, origin and rate limits, that the kind and the source are not theirs to
| choose, that nothing about the sender is kept, that the answer is always 204 for a report of the right shape and never
| says why anything else was refused, and that a report is recorded the way every other failure is (ErrorRecorder, after
| the answer). What a stored issue holds once the recorder has it is ErrorRecorderTest; the script itself is
| ErrorReportScriptTest.
*/

beforeEach(function () {
    $this->spool = errorsClientSpool();
    ErrorSpool::forgetLost(ErrorSpool::lost());

    // The production shape of the answers, and no log file.
    config(['app.debug' => false, 'logging.default' => 'null']);
});

afterEach(function () {
    errorsClientForget($this->spool);
    ErrorSpool::forgetLost(ErrorSpool::lost());
});

/** A report the way the website's script sends it. Any field replaced. */
function errorsClientSite(array $with = []): array
{
    return $with + [
        'class' => 'TypeError',
        'message' => "Cannot read properties of undefined (reading 'value')",
        'trace' => "at pick (/build/assets/app-Bx9a2c1d.js:12:34)\nat HTMLSelectElement.<anonymous> (/build/assets/app-Bx9a2c1d.js:56:7)",
        'route' => '/app/contracts/create',
    ];
}

/** A report the way the phone app sends it. Any field replaced. */
function errorsClientApp(array $with = []): array
{
    return $with + [
        'class' => '_TypeError',
        'message' => "type 'Null' is not a subtype of type 'String'",
        'trace' => "#0      CustomerCard.build (package:qistas/features/customers/customer_card.dart:41:12)\n#1      StatelessElement.build (package:flutter/src/widgets/framework.dart:5687:15)",
        'version' => '1.30.0',
        'os' => 'android',
        'os_version' => '14',
        'device' => 'phone',
    ];
}

/**
 * A request the way a browser's beacon makes it: a POST of JSON, accepting anything. $server holds what the test wants
 * to say about the sender (REMOTE_ADDR, HTTP_ORIGIN, HTTP_USER_AGENT, HTTP_AUTHORIZATION, CONTENT_TYPE ...).
 *
 * @param  array<string, mixed>|string  $body
 * @param  array<string, string>  $server
 */
function errorsClientSend(string $uri, array|string $body, array $server = []): TestResponse
{
    $content = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Every request is a new life of the application on the live site; here they share one, and its token guard would
    // remember the first person it met.
    app('auth')->forgetGuards();

    return test()->call('POST', $uri, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*'], $content);
}

/** A report to the website's endpoint. */
function errorsClientSendSite(array|string $body, array $server = []): TestResponse
{
    return errorsClientSend('/client-errors', $body, $server);
}

/** A report to the phone app's endpoint. */
function errorsClientSendApp(array|string $body, array $server = []): TestResponse
{
    return errorsClientSend('/api/v1/client-errors', $body, $server);
}

/** A sign-in token of $user, the way the phone holds one. */
function errorsClientToken(User $user, ?DateTimeInterface $expires = null): string
{
    return $user->createToken('phone', ['app'], $expires)->plainTextToken;
}

/** The person hash the design gives: 16 hex characters of an HMAC-SHA256 of the user id with the application key. */
function errorsClientPerson(string $userId): string
{
    return substr(hash_hmac('sha256', $userId, config('app.key')), 0, 16);
}

/** A word for $n, in letters (digits are folded away by the fingerprint, so a number would not make a different issue). */
function errorsClientWord(int $n): string
{
    $word = '';

    do {
        $word = chr(97 + $n % 26).$word;
        $n = intdiv($n, 26);
    } while ($n > 0);

    return $word;
}

/** The spool of this test alone (in the system's temporary folder), so no test ever writes storage/logs. */
function errorsClientSpool(): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-client-'.Str::random(12).DIRECTORY_SEPARATOR.'error-spool.ndjson';
    config(['qistas.errors.spool_path' => $path]);

    return $path;
}

function errorsClientForget(string $path): void
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
function errorsClientStored(): string
{
    return collect(['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'])
        ->map(fn (string $table) => DB::table($table)->get()->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->implode("\n");
}

function errorsClientNothingStored(): void
{
    foreach (['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'] as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} was written");
    }
}

/** How many issues were made from what a browser or a phone reported (the error pages the refusals leave are not these). */
function errorsClientReports(): int
{
    return ErrorIssue::query()->whereIn('kind', ['script', 'crash'])->count();
}

function errorsClientNoise(string $kind): int
{
    return (int) DB::table('error_noise_days')->where('kind', $kind)->sum('count');
}

/** A body of exactly $bytes bytes: the report plus a field nobody reads, padded. */
function errorsClientPadded(array $report, int $bytes): string
{
    $base = json_encode($report + ['pad' => ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = json_encode($report + ['pad' => str_repeat('x', $bytes - strlen($base))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect(strlen($body))->toBe($bytes);

    return $body;
}

/** The database cannot find the issues table: as good as away, for the recorder. */
function errorsClientAway(): void
{
    Schema::rename('error_issues', 'error_issues_away');
}

// ---- what a report becomes -----------------------------------------------------------------------------------------

it('a script report creates a site/script issue seen on the web, with its route pattern, and answers 204 with no body', function () {
    $response = errorsClientSendSite(errorsClientSite())->assertNoContent();

    $issue = ErrorIssue::query()->sole();
    $sample = $issue->samples()->sole();

    expect($response->getContent())->toBe('')
        ->and($issue->only(['source', 'kind', 'class', 'status', 'occurrences', 'people']))->toBe([
            'source' => 'site', 'kind' => 'script', 'class' => 'TypeError', 'status' => 'open', 'occurrences' => 1, 'people' => 0,
        ])
        ->and($issue->title)->toContain('TypeError')
        ->and($sample->only(['platform', 'device', 'route', 'version', 'status', 'method']))->toBe([
            'platform' => 'web', 'device' => null, 'route' => '/app/contracts/create', 'version' => null, 'status' => null, 'method' => null,
        ])
        ->and($sample->message)->toBe("Cannot read properties of undefined (reading 'value')")
        ->and($sample->trace)->toContain('app-Bx9a2c1d.js');
});

it('the same script error again only counts, and ids in the route are a pattern', function () {
    errorsClientSendSite(errorsClientSite(['route' => '/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f?tab=payments']))->assertNoContent();
    errorsClientSendSite(errorsClientSite(['route' => '/app/customers/7c1e0b8a-0e6a-4f4d-529a-4e5f3f1d1b2c']))->assertNoContent();

    $issue = ErrorIssue::query()->sole();

    expect($issue->occurrences)->toBe(2)
        ->and($issue->samples()->pluck('route')->unique()->all())->toBe(['/app/customers/{id}']);
});

it('a phone report creates an app/crash issue with platform android and device mobile', function () {
    errorsClientSendApp(errorsClientApp())->assertNoContent();

    $issue = ErrorIssue::query()->sole();
    $sample = $issue->samples()->sole();

    expect($issue->only(['source', 'kind', 'class', 'status', 'occurrences', 'people', 'first_version', 'last_version']))->toBe([
        'source' => 'app', 'kind' => 'crash', 'class' => '_TypeError', 'status' => 'open', 'occurrences' => 1, 'people' => 0,
        'first_version' => '1.30.0', 'last_version' => '1.30.0',
    ])
        ->and($sample->only(['platform', 'device', 'version', 'route', 'status', 'method']))->toBe([
            'platform' => 'android', 'device' => 'mobile', 'version' => '1.30.0', 'route' => null, 'status' => null, 'method' => null,
        ])
        ->and($sample->trace)->toContain('package:qistas/features/customers/customer_card.dart');
});

it('the phone says ios or android, and phone or tablet: tablet stays tablet', function (string $os, string $device, string $stored) {
    errorsClientSendApp(errorsClientApp(['os' => $os, 'device' => $device]))->assertNoContent();

    $sample = ErrorIssue::query()->sole()->samples()->sole();

    expect($sample->platform)->toBe($os)->and($sample->device)->toBe($stored);
})->with([
    'android phone' => ['android', 'phone', 'mobile'],
    'android tablet' => ['android', 'tablet', 'tablet'],
    'iphone' => ['ios', 'phone', 'mobile'],
    'ipad' => ['ios', 'tablet', 'tablet'],
]);

it('the Android or iOS version is read and ignored: it is stored nowhere', function () {
    errorsClientSendApp(errorsClientApp(['os_version' => 'OSVERSION-MARKER-14']))->assertNoContent();

    expect(ErrorIssue::query()->count())->toBe(1)->and(errorsClientStored())->not->toContain('OSVERSION-MARKER');
});

// ---- who sent it ----------------------------------------------------------------------------------------------------

it('a report with an app token stores only the person hash; without a token none', function () {
    $user = User::factory()->create();

    errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer '.errorsClientToken($user)])->assertNoContent();

    $issue = ErrorIssue::query()->sole();
    expect($issue->people)->toBe(1)
        ->and(DB::table('error_issue_people')->pluck('person')->all())->toBe([errorsClientPerson($user->id)])
        ->and(errorsClientStored())->not->toContain($user->id)->not->toContain($user->email);

    // The same person again is the same person; a report with no token is nobody.
    errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer '.errorsClientToken($user)])->assertNoContent();
    errorsClientSendApp(errorsClientApp())->assertNoContent();

    expect($issue->refresh()->only(['occurrences', 'people']))->toBe(['occurrences' => 3, 'people' => 1])
        ->and(DB::table('error_issue_people')->count())->toBe(1);
});

it('a bad, expired or revoked token is nobody, and the report is still taken', function (string $kind) {
    $user = User::factory()->create();

    $header = match ($kind) {
        'garbage' => 'Bearer not-a-token',
        'unknown' => 'Bearer 99999|'.Str::random(40),
        'empty' => 'Bearer ',
        'basic' => 'Basic '.base64_encode('a:b'),
        'expired' => 'Bearer '.errorsClientToken($user, now()->subMinute()),
        'revoked' => (function () use ($user): string {
            $token = $user->createToken('phone', ['app']);
            $header = 'Bearer '.$token->plainTextToken;
            $token->accessToken->delete();

            return $header;
        })(),
    };

    errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => $header])->assertNoContent();

    expect(ErrorIssue::query()->sole()->people)->toBe(0)->and(DB::table('error_issue_people')->count())->toBe(0);
})->with(['garbage', 'unknown', 'empty', 'basic', 'expired', 'revoked']);

it('a person id sent in the body is not a person', function () {
    $user = User::factory()->create();

    errorsClientSendApp(errorsClientApp(['user_id' => $user->id, 'userId' => $user->id, 'email' => $user->email]))->assertNoContent();
    errorsClientSendSite(errorsClientSite(['user_id' => $user->id, 'userId' => $user->id]))->assertNoContent();

    expect(DB::table('error_issue_people')->count())->toBe(0)->and(errorsClientStored())->not->toContain($user->id)->not->toContain($user->email);
});

it('nothing about the sender is stored: not the address, the browser, the cookies, the referrer or the token', function () {
    $user = User::factory()->create();
    $token = errorsClientToken($user);
    $sender = [
        'REMOTE_ADDR' => '203.0.113.77',
        'HTTP_USER_AGENT' => 'ProbeBrowser/9.9 (SecretMarker; Windows NT 10.0)',
        'HTTP_REFERER' => 'https://localhost/app/customers/RefererMarker?q=1',
        'HTTP_COOKIE' => 'probe=CookieMarker',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
    ];

    errorsClientSendSite(errorsClientSite(), $sender)->assertNoContent();
    errorsClientSendApp(errorsClientApp(), $sender + ['HTTP_AUTHORIZATION' => 'Bearer '.$token])->assertNoContent();

    $stored = errorsClientStored();
    foreach (['203.0.113.77', '198.51.100.9', 'ProbeBrowser', 'SecretMarker', 'RefererMarker', 'CookieMarker', $token, explode('|', $token)[1]] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});

// ---- what a report may say ------------------------------------------------------------------------------------------

it('personal data in the message and the trace is masked, from the browser and from the phone', function () {
    $leaky = [
        'message' => 'Failed for sara.ali@example.com, phone 0501234567, token 12|abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN, bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk',
        'trace' => "at load (/build/assets/app-Bx9a2c1d.js:1:2)\nat https://www.example.com/app/customers?email=sara.ali@example.com&access_token=abc123def456ghi789 (/build/assets/app-Bx9a2c1d.js:3:4)\nnational id 1098765432",
    ];

    errorsClientSendSite(errorsClientSite($leaky))->assertNoContent();
    errorsClientSendApp(errorsClientApp($leaky + ['class' => 'LeakyError']))->assertNoContent();

    $stored = errorsClientStored();

    expect(ErrorIssue::query()->count())->toBe(2);
    foreach (['sara.ali@example.com', '0501234567', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN', 'eyJhbGciOiJIUzI1NiJ9', 'access_token=abc123def456ghi789', '1098765432'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
});

it('markup in a message is stored as text, and a client report never sends an e-mail or counts as alerted', function () {
    Mail::fake();
    Notification::fake();
    $created = [];
    Event::listen(ErrorIssueCreated::class, function (ErrorIssueCreated $event) use (&$created): void {
        $created[] = $event->issue->only(['source', 'kind']);
    });

    $markup = '<script>alert(1)</script><img src=x onerror=alert(1)>';
    errorsClientSendSite(errorsClientSite(['message' => $markup]))->assertNoContent();
    errorsClientSendApp(errorsClientApp(['message' => $markup]))->assertNoContent();

    expect(DB::table('error_occurrences')->pluck('message')->all())->toBe([$markup, $markup])
        ->and(ErrorIssue::query()->whereNotNull('alerted_at')->count())->toBe(0)
        // The recorder tells the listeners about every new issue; it is the instant e-mail's job to leave these two out.
        ->and($created)->toEqualCanonicalizing([['source' => 'site', 'kind' => 'script'], ['source' => 'app', 'kind' => 'crash']]);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('unknown fields are ignored', function () {
    errorsClientSendSite(errorsClientSite(['url' => 'https://localhost/secret?token=UrlMarker', 'cookie' => 'CookieMarker', 'userAgent' => 'AgentMarker', 'ip' => '192.0.2.55', 'extra' => ['a' => 'NestedMarker']]))->assertNoContent();
    errorsClientSendApp(errorsClientApp(['phone' => '0501234567', 'customer' => 'Sara Ali Marker', 'amount' => '1500.00', 'account' => 'AccountMarker']))->assertNoContent();

    $stored = errorsClientStored();

    expect(ErrorIssue::query()->count())->toBe(2);
    foreach (['UrlMarker', 'CookieMarker', 'AgentMarker', '192.0.2.55', 'NestedMarker', '0501234567', 'Sara Ali Marker', '1500.00', 'AccountMarker'] as $unknown) {
        expect($stored)->not->toContain($unknown);
    }
});

it('kind and source cannot be chosen by the sender', function () {
    $claims = ['source' => 'database', 'kind' => 'exception', 'platform' => 'server', 'status' => 500, 'method' => 'POST', 'at' => '2001-01-01T00:00:00Z', 'fingerprint' => str_repeat('a', 40)];

    errorsClientSendSite(errorsClientSite($claims + ['device' => 'desktop']))->assertNoContent();
    errorsClientSendApp(errorsClientApp($claims + ['os' => 'ios']))->assertNoContent();

    $issues = ErrorIssue::query()->orderBy('source')->get();
    expect($issues->map(fn (ErrorIssue $issue) => $issue->source.'/'.$issue->kind)->all())->toBe(['app/crash', 'site/script']);

    foreach ($issues as $issue) {
        $sample = $issue->samples()->sole();
        expect($sample->status)->toBeNull()->and($sample->method)->toBeNull()
            ->and($sample->occurred_at->year)->toBe((int) now()->year)
            ->and($issue->fingerprint)->not->toBe(str_repeat('a', 40));
    }

    expect(ErrorIssue::query()->where('source', 'site')->sole()->samples()->sole()->platform)->toBe('web')
        ->and(ErrorIssue::query()->where('source', 'site')->sole()->samples()->sole()->device)->toBeNull()
        ->and(ErrorIssue::query()->where('source', 'app')->sole()->samples()->sole()->platform)->toBe('ios');
});

it('the intake takes a browser as a script and a phone as a crash', function (string $source, string $kind, string $platform) {
    app(ClientErrorIntake::class)->accept($source, $kind, errorsClientSite(), null, $platform);
    app(ErrorCapture::class)->flush(); // the recording waits for the end of the answer; there is none here

    expect(ErrorIssue::query()->count())->toBe(1)
        ->and(ErrorIssue::query()->sole()->only(['source', 'kind']))->toBe(['source' => $source, 'kind' => $kind]);
})->with([
    'a browser' => ['site', 'script', 'web'],
    'an android phone' => ['app', 'crash', 'android'],
    'an iphone' => ['app', 'crash', 'ios'],
]);

it('the intake turns away anything else, whatever the caller says', function (string $source, string $kind, string $platform) {
    app(ClientErrorIntake::class)->accept($source, $kind, errorsClientSite(), null, $platform);
    app(ErrorCapture::class)->flush();

    errorsClientNothingStored();
})->with([
    'a database exception' => ['database', 'exception', 'server'],
    'a site exception' => ['site', 'exception', 'server'],
    'a site crash' => ['site', 'crash', 'web'],
    'an app script' => ['app', 'script', 'android'],
    'a browser that says android' => ['site', 'script', 'android'],
    'a phone that says web' => ['app', 'crash', 'web'],
    'a phone that says server' => ['app', 'crash', 'server'],
    'an api slow query' => ['api', 'slow_query', 'server'],
]);

// ---- shape: a stranger's body -----------------------------------------------------------------------------------------

it('a report of the wrong shape is refused with 422 and no words', function (string $endpoint, array $report) {
    $response = $endpoint === 'site' ? errorsClientSendSite($report) : errorsClientSendApp($report);

    $response->assertStatus(422);
    expect($response->getContent())->toBe('');
    errorsClientNothingStored();
})->with(function () {
    $site = fn (array $with) => ['site', errorsClientSite($with)];
    $app = fn (array $with) => ['app', errorsClientApp($with)];

    return [
        'site: no message' => $site(['message' => null]),
        'site: an empty message' => $site(['message' => '   ']),
        'site: a message of 501 characters' => $site(['message' => str_repeat('m', 501)]),
        'site: a class of 161 characters' => $site(['class' => str_repeat('c', 161)]),
        'site: a trace of 6001 characters' => $site(['trace' => str_repeat('t', 6001)]),
        'site: a route of 161 characters' => $site(['route' => '/'.str_repeat('r', 160)]),
        'site: a message that is a list' => $site(['message' => ['a', 'b']]),
        'site: a message that is a number' => $site(['message' => 12345]),
        'site: a trace that is an object' => $site(['trace' => ['a' => 'b']]),
        'app: no class' => $app(['class' => null]),
        'app: a class of 161 characters' => $app(['class' => str_repeat('c', 161)]),
        'app: a message of 501 characters' => $app(['message' => str_repeat('m', 501)]),
        'app: a trace of 8001 characters' => $app(['trace' => str_repeat('t', 8001)]),
        'app: a version of 17 characters' => $app(['version' => str_repeat('1', 17)]),
        'app: an os version of 25 characters' => $app(['os_version' => str_repeat('1', 25)]),
        'app: no os' => $app(['os' => null]),
        'app: windows' => $app(['os' => 'windows']),
        'app: a desktop' => $app(['device' => 'desktop']),
        'app: a class that is a list' => $app(['class' => ['a']]),
    ];
});

it('a body that is not an object is refused with 422', function (string $body) {
    errorsClientSendSite($body)->assertStatus(422);
    errorsClientSendApp($body)->assertStatus(422);

    errorsClientNothingStored();
})->with(['not json' => '{"message": ', 'a list' => '[1, 2, 3]', 'a number' => '42', 'a string' => '"boom"', 'nothing' => '', 'an empty object' => '{}']);

it('a report at the limits is taken', function () {
    errorsClientSendSite(errorsClientSite(['class' => str_repeat('c', 160), 'message' => str_repeat('m', 500), 'trace' => str_repeat("at x (/build/assets/app.js:1:2)\n", 150), 'route' => '/'.str_repeat('r', 159)]))->assertNoContent();
    errorsClientSendApp(errorsClientApp(['class' => str_repeat('d', 160), 'message' => str_repeat('n', 500), 'trace' => str_repeat('t', 8000), 'version' => str_repeat('1', 16), 'os_version' => str_repeat('1', 24)]))->assertNoContent();

    expect(ErrorIssue::query()->count())->toBe(2);
});

it('a report with only what it must say is taken', function () {
    errorsClientSendSite(['message' => 'Boom'])->assertNoContent();
    errorsClientSendApp(['class' => 'StateError', 'os' => 'android'])->assertNoContent();

    expect(ErrorIssue::query()->count())->toBe(2);
});

// ---- limits: size, type, origin, rate ------------------------------------------------------------------------------

it('a body over the limit is refused with 413, and nothing is stored', function () {
    $site = errorsClientSite();
    $app = errorsClientApp();

    // Two reports of the same kind of error: only the ones at or under the limit are counted.
    errorsClientSendSite(errorsClientPadded($site, 8192))->assertNoContent();
    $response = errorsClientSendSite(errorsClientPadded($site, 8193));
    $response->assertStatus(413);
    expect($response->getContent())->toBe('');

    errorsClientSendApp(errorsClientPadded($app, 8193))->assertNoContent(); // the phone is allowed twice as much
    errorsClientSendApp(errorsClientPadded($app, 16384))->assertNoContent();
    $response = errorsClientSendApp(errorsClientPadded($app, 16385));
    $response->assertStatus(413);
    expect($response->getContent())->toBe('');

    expect(ErrorIssue::query()->orderBy('source')->pluck('occurrences', 'source')->all())->toBe(['app' => 2, 'site' => 1]);
});

it('a body that says it is big is refused before it is read, whatever it holds', function () {
    errorsClientSendSite(errorsClientSite(), ['CONTENT_LENGTH' => '100000'])->assertStatus(413);
    errorsClientSendApp(errorsClientApp(), ['CONTENT_LENGTH' => '100000'])->assertStatus(413);

    errorsClientNothingStored();
});

it('a non-JSON content type is refused with 415 by the website', function (string $type) {
    $response = errorsClientSendSite(json_encode(errorsClientSite(), JSON_THROW_ON_ERROR), ['CONTENT_TYPE' => $type]);

    $response->assertStatus(415);
    expect($response->getContent())->toBe('');
    errorsClientNothingStored();
})->with(['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'text/html', 'application/octet-stream', 'application/xml']);

it('JSON with a charset is JSON', function () {
    errorsClientSendSite(errorsClientSite(), ['CONTENT_TYPE' => 'application/json; charset=UTF-8'])->assertNoContent();

    expect(ErrorIssue::query()->count())->toBe(1);
});

it('a foreign Origin is refused with 403 while a missing Origin is accepted', function (?string $origin, bool $taken) {
    $server = $origin === null ? [] : ['HTTP_ORIGIN' => $origin];

    $response = errorsClientSendSite(errorsClientSite(), $server);

    if ($taken) {
        $response->assertNoContent();
        expect(errorsClientReports())->toBe(1);
    } else {
        $response->assertForbidden();
        expect($response->getContent())->toBe('')->and(errorsClientReports())->toBe(0);
    }
})->with([
    'no Origin (a privacy setting strips it)' => [null, true],
    'our own' => ['http://localhost', true],
    'our own, in capitals' => ['http://LOCALHOST', true],
    'our own on https (a proxy hides it)' => ['https://localhost', true],
    'our own on another port' => ['http://localhost:8000', true],
    'another site' => ['https://evil.example', false],
    'our name inside theirs' => ['http://localhost.evil.example', false],
    'ours as their user name' => ['http://localhost@evil.example', false],
    'ours as their path' => ['https://evil.example/localhost', false],
    'a sub-domain of ours' => ['http://app.localhost', false],
    'null (a sandboxed page)' => ['null', false],
    'not an address' => ['not an origin', false],
    'empty' => ['', false],
]);

it('the Origin of the phone is not looked at: an app sends none', function () {
    errorsClientSendApp(errorsClientApp(), ['HTTP_ORIGIN' => 'https://evil.example'])->assertNoContent();

    expect(ErrorIssue::query()->count())->toBe(1);
});

it('the website endpoint answers 20 reports a minute from one address, then 429; another address is not slowed', function () {
    foreach (range(1, 20) as $n) {
        errorsClientSendSite(errorsClientSite(), ['REMOTE_ADDR' => '10.20.0.1'])->assertNoContent();
    }

    $response = errorsClientSendSite(errorsClientSite(), ['REMOTE_ADDR' => '10.20.0.1']);
    $response->assertStatus(429);
    expect($response->getContent())->toBe('')->and($response->headers->has('Retry-After'))->toBeTrue();

    errorsClientSendSite(errorsClientSite(), ['REMOTE_ADDR' => '10.20.0.2'])->assertNoContent();

    // Twenty reports counted on one issue, and the one more from the other address; the refused one is nowhere.
    expect(ErrorIssue::query()->where('kind', 'script')->sole()->occurrences)->toBe(21);

    // A minute later the first address may report again.
    $this->travel(61)->seconds();
    errorsClientSendSite(errorsClientSite(), ['REMOTE_ADDR' => '10.20.0.1'])->assertNoContent();
});

it('the phone endpoint answers 30 reports a minute from one token, then 429; another token or an anonymous phone is not slowed', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $header = ['HTTP_AUTHORIZATION' => 'Bearer '.errorsClientToken($mine), 'REMOTE_ADDR' => '10.30.0.1'];

    foreach (range(1, 30) as $n) {
        errorsClientSendApp(errorsClientApp(), $header)->assertNoContent();
    }

    $response = errorsClientSendApp(errorsClientApp(), $header);
    $response->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect($response->headers->has('Retry-After'))->toBeTrue();

    // The same address with another person's token, with another phone of the same person, and with none: each has a
    // place of its own (the limit is per token).
    errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer '.errorsClientToken($theirs)] + $header)->assertNoContent();
    errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer '.errorsClientToken($mine)] + $header)->assertNoContent();
    errorsClientSendApp(errorsClientApp(), ['REMOTE_ADDR' => '10.30.0.1'])->assertNoContent();

    expect(ErrorIssue::query()->sole()->occurrences)->toBe(33); // 30 + the other person's + the other phone's + the anonymous one
});

it('a stream of made-up tokens does not buy more than the address allows', function () {
    foreach (range(1, 30) as $n) {
        errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer made-up-'.$n, 'REMOTE_ADDR' => '10.31.0.1'])->assertNoContent();
    }

    errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer made-up-31', 'REMOTE_ADDR' => '10.31.0.1'])->assertStatus(429);
});

it('the 21st NEW issue in a minute is dropped and counted as dropped, while reports for existing issues still count', function () {
    // The budget is counted by the clock's minute: keep the whole test inside one.
    $this->freezeTime();

    // Twenty different failures, each from an address of its own (the request limit would answer 429 first otherwise).
    foreach (range(1, 20) as $n) {
        errorsClientSendSite(errorsClientSite(['message' => 'Failure '.errorsClientWord($n)]), ['REMOTE_ADDR' => '10.40.0.'.$n])->assertNoContent();
    }

    expect(ErrorIssue::query()->count())->toBe(20)->and(errorsClientNoise('dropped'))->toBe(0);

    // The 21st failure is answered like the others (nobody is told) and is counted as dropped.
    errorsClientSendSite(errorsClientSite(['message' => 'Failure '.errorsClientWord(21)]), ['REMOTE_ADDR' => '10.40.0.21'])->assertNoContent();
    expect(ErrorIssue::query()->count())->toBe(20)->and(errorsClientNoise('dropped'))->toBe(1);

    // The phone draws on the same budget of client reports.
    errorsClientSendApp(errorsClientApp(['class' => 'DartError'.errorsClientWord(22)]), ['REMOTE_ADDR' => '10.40.0.22'])->assertNoContent();
    expect(ErrorIssue::query()->count())->toBe(20)->and(errorsClientNoise('dropped'))->toBe(2);

    // A failure that is known already is still counted, however many new ones were turned away.
    errorsClientSendSite(errorsClientSite(['message' => 'Failure '.errorsClientWord(1)]), ['REMOTE_ADDR' => '10.40.0.23'])->assertNoContent();
    expect(ErrorIssue::query()->count())->toBe(20)
        ->and(ErrorIssue::query()->where('title', 'like', '%Failure b%')->sole()->occurrences)->toBe(2)
        ->and(errorsClientNoise('dropped'))->toBe(2);
});

// ---- no session, no cookie, no token ----------------------------------------------------------------------------------

it('the website endpoint needs no CSRF token, starts no session and sets no cookie', function () {
    // The framework skips the CSRF check while it runs tests; pretend to be the live site, where it does not.
    app()->detectEnvironment(fn () => 'production');
    $this->post('/schedule-preview', [])->assertStatus(419); // a page's form without its token is refused: the check is on

    $response = errorsClientSendSite(errorsClientSite());

    $response->assertNoContent();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and(errorsClientReports())->toBe(1);
});

it('sets no cookie even when the language is being chosen, a language cookie or a session cookie came with the request', function () {
    $response = test()
        ->withCookie('qistas_locale', 'ar')
        ->withCookie('qistas_session', 'a-session-the-browser-had')
        ->call('POST', '/client-errors?lang=fr', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(errorsClientSite(), JSON_THROW_ON_ERROR));

    $response->assertNoContent();
    expect($response->headers->getCookies())->toBe([])->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('the phone endpoint sets no cookie either', function () {
    $user = User::factory()->create();

    $response = errorsClientSendApp(errorsClientApp(), ['HTTP_AUTHORIZATION' => 'Bearer '.errorsClientToken($user)])->assertNoContent();

    expect($response->headers->getCookies())->toBe([])->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('the website route runs without session, forgery check, language or duplicate-submission middleware; the phone route is outside the sign-in group', function () {
    $router = app('router');
    $site = $router->gatherRouteMiddleware(Route::getRoutes()->getByName('client-errors.store'));
    $app = $router->gatherRouteMiddleware(Route::getRoutes()->getByName('api.client-errors.store'));

    foreach ([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, PreventDuplicateSubmissions::class, SetLocale::class, KeepStaffOutOfTwoFactor::class] as $middleware) {
        expect($site)->not->toContain($middleware);
    }

    expect($site)->toContain(ThrottleRequests::class.':client-errors')->toContain(GuardClientReport::class.':site')
        ->and($app)->toContain(ThrottleRequests::class.':client-errors-api')->toContain(GuardClientReport::class.':app');

    // Not behind the sign-in group, and not asking the database for a language.
    foreach ([Authenticate::class.':sanctum', EnsureAccountActive::class, SetCurrentTenant::class, ReadOnlyWhileDeleting::class, SetApiLocale::class] as $middleware) {
        expect($app)->not->toContain($middleware);
    }
});

it('the forgery check lists the website endpoint as one it never verifies', function () {
    $check = app(PreventRequestForgery::class);
    $listed = new ReflectionMethod($check, 'inExceptArray');

    expect($listed->invoke($check, Request::create('/client-errors', 'POST')))->toBeTrue()
        ->and($listed->invoke($check, Request::create('/schedule-preview', 'POST')))->toBeFalse();
});

it('the routes are where the app and the script expect them', function () {
    expect(route('client-errors.store', absolute: false))->toBe('/client-errors')
        ->and(route('api.client-errors.store', absolute: false))->toBe('/api/v1/client-errors');

    // Only a POST: a page opened by hand finds nothing.
    $this->get('/client-errors')->assertNotFound();
    $this->getJson('/api/v1/client-errors')->assertNotFound();
});

// ---- a reporter is never given an error back -------------------------------------------------------------------------

it('a valid report always gets 204: a recorder that throws, a database that is away, capture switched off', function () {
    // The recorder itself falls over.
    app()->bind(ErrorRecorder::class, fn () => throw new RuntimeException('The recorder cannot even be built'));
    errorsClientSendSite(errorsClientSite())->assertNoContent();
    errorsClientSendApp(errorsClientApp())->assertNoContent();
    app()->offsetUnset(ErrorRecorder::class);
    expect(ErrorIssue::query()->count())->toBe(0);

    // The database is away: the scrubbed report waits in the spool.
    errorsClientAway();
    errorsClientSendSite(errorsClientSite())->assertNoContent();
    errorsClientSendApp(errorsClientApp())->assertNoContent();

    $lines = array_map(fn (string $line) => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->spool)))));
    expect($lines)->toHaveCount(2)
        ->and(collect($lines)->map(fn (array $line) => $line['source'].'/'.$line['kind'].'/'.$line['platform'])->sort()->values()->all())->toBe(['app/crash/android', 'site/script/web']);
});

it('a valid report is answered even when the spool cannot be written either', function () {
    errorsClientAway();
    $file = (string) tempnam(sys_get_temp_dir(), 'qistas');
    config(['qistas.errors.spool_path' => $file.DIRECTORY_SEPARATOR.'error-spool.ndjson']);

    errorsClientSendSite(errorsClientSite())->assertNoContent();
    errorsClientSendApp(errorsClientApp())->assertNoContent();

    unlink($file);
});

it('QISTAS_ERRORS=false: the report is answered 204 and nothing is stored, not even in the spool', function () {
    config(['qistas.errors.enabled' => false]);

    errorsClientSendSite(errorsClientSite())->assertNoContent();
    errorsClientSendApp(errorsClientApp())->assertNoContent();

    errorsClientNothingStored();
    expect(file_exists($this->spool))->toBeFalse();
});

it('the report is recorded after the answer is made, not while it is made', function () {
    $kernel = app(HttpKernel::class);
    $request = Request::create('/client-errors', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(errorsClientSite(), JSON_THROW_ON_ERROR));

    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(204)
        ->and(ErrorIssue::query()->count())->toBe(0)
        ->and(count(app(DeferredCallbackCollection::class)))->toBe(1);

    $kernel->terminate($request, $response);

    expect(ErrorIssue::query()->count())->toBe(1);
});
