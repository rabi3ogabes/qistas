<?php

use App\Errors\DigestOutcome;
use App\Errors\ErrorAlerts;
use App\Errors\ErrorEvent;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorTrend;
use App\Errors\ErrorTriage;
use App\Errors\Events\ErrorIssueCreated;
use App\Errors\Recorded;
use App\Mail\ErrorAlertMail;
use App\Mail\ErrorDigestMail;
use App\Mail\MailSettings;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/*
| The error center's two e-mails (design section 8): the instant one for a brand-new server error, and the daily digest of
| what is new, came back or is growing. They go to the active super admins only, in each one's own language, never carry a
| trace, a message, a route or a person, and a failure to send them never touches the recorder. The caps (3 an hour, 10 a
| day) are counted through the rate limiter; what a cap holds back waits for the digest.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30:00', 'UTC'));
    config(['app.url' => 'https://qistas.example']);
    RateLimiter::clear('error-alerts-hour');
    RateLimiter::clear('error-alerts-day');
    Mail::fake();
});

afterEach(function () {
    RateLimiter::clear('error-alerts-hour');
    RateLimiter::clear('error-alerts-day');
});

/** Letters only, because the fingerprint folds digits away: each $n makes an issue of its own. */
function errorsAlertWord(int $n): string
{
    $word = '';

    do {
        $word = chr(97 + $n % 26).$word;
        $n = intdiv($n, 26);
    } while ($n > 0);

    return $word;
}

/** What the capture layer hands the recorder for a server error that sends the instant e-mail, any field replaced. */
function errorsAlertEvent(array $with = []): ErrorEvent
{
    return new ErrorEvent(...($with + [
        'source' => 'site',
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'message' => 'The payment gateway refused the call',
        'trace' => "#0 app/Billing/Gateway.php(48): App\\Billing\\Gateway->charge()\n#1 {main}",
        'status' => 500,
        'method' => 'POST',
        'route' => '/app/contracts/{contract}/payments',
        'version' => '1.30.0',
        'platform' => 'server',
        'device' => null,
        'userId' => null,
    ]));
}

/** A server error of its own: number $n is never the same issue as number $m. */
function errorsAlertFailure(int $n, array $with = []): ErrorEvent
{
    return errorsAlertEvent($with + ['class' => 'AlertFailure'.errorsAlertWord($n), 'message' => 'Broke because of '.errorsAlertWord($n)]);
}

function errorsAlertRecord(ErrorEvent $event): Recorded
{
    return app(ErrorRecorder::class)->record($event);
}

/** An issue straight into the table; by default an old, quiet one that qualifies for nothing. */
function errorsAlertIssue(array $attributes = [], array $days = []): ErrorIssue
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
        'first_seen_at' => now()->subDays(5),
        'last_seen_at' => now()->subDays(5),
        'first_version' => '1.30.0',
        'last_version' => '1.30.0',
    ]);

    foreach ($days as $day => $count) {
        DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => $day, 'count' => $count]);
    }

    return $issue;
}

/** An old issue seen $today times today and $before a day on each of the seven days before (or the seven given). */
function errorsAlertBusy(int $today, int|array $before, array $attributes = []): ErrorIssue
{
    $before = is_array($before) ? $before : array_fill(0, 7, $before);
    $days = [now()->toDateString() => $today];

    foreach ($before as $i => $count) {
        $days[now()->subDays(7 - $i)->toDateString()] = $count;
    }

    return errorsAlertIssue($attributes + [
        'occurrences' => $today + array_sum($before),
        'last_seen_at' => now()->subHour(),
    ], array_filter($days, fn (int $count) => $count > 0));
}

/** An issue first seen $hours hours ago and not since. */
function errorsAlertNew(float $hours = 1, array $attributes = []): ErrorIssue
{
    $at = now()->subMinutes((int) round($hours * 60));

    return errorsAlertIssue($attributes + ['first_seen_at' => $at, 'last_seen_at' => $at]);
}

/** A resolved issue that came back $hours hours ago. */
function errorsAlertBack(float $hours = 1, array $attributes = []): ErrorIssue
{
    return errorsAlertIssue($attributes + [
        'status' => 'open',
        'reopened_at' => now()->subMinutes((int) round($hours * 60)),
        'reopened_version' => '1.30.0',
        'resolved_at' => now()->subDays(2),
        'last_seen_at' => now()->subMinutes((int) round($hours * 60)),
    ]);
}

/** A person on the platform team who may be told, in a language of their own. */
function errorsAlertSuper(string $locale = 'en', ?string $email = null): User
{
    return User::factory()->platformAdmin('super_admin')->create(array_filter(['locale' => $locale, 'email' => $email]));
}

/** Did the digest go out? */
function errorsAlertDigest(): bool
{
    return errorsAlertOutcome() === DigestOutcome::Sent;
}

/** What the digest did: sent, had nothing to send, or could not be sent. */
function errorsAlertOutcome(): DigestOutcome
{
    return app(ErrorAlerts::class)->digest(now());
}

/** The issue ids in each list of the one digest that was sent. */
function errorsAlertLists(): array
{
    $mail = Mail::sent(ErrorDigestMail::class)->first();
    $ids = fn (array $issues): array => collect($issues)->pluck('id')->all();

    return ['new' => $ids($mail->new), 'cameBack' => $ids($mail->cameBack), 'growing' => $ids($mail->growing)];
}

/** Every query that runs while $work does. */
function errorsAlertQueries(Closure $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return $queries;
}

/** Puts the real mail manager back in place of Mail::fake(): the next mail builds its mailer the way the application does. */
function errorsAlertLiftFake(): void
{
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');
    // forgetInstance() leaves the container believing the manager was built, which would skip the hook that applies the saved server
    (function (): void {
        unset($this->resolved['mail.manager']);
    })->call(app());
}

/**
 * A real mailer whose server is down for the addresses in $broken (every address when it is ['*']): the way to see what
 * happens when a mail cannot go. Mail::fake() is lifted for it.
 */
function errorsAlertMailer(array $broken = ['*']): object
{
    $transport = new class extends AbstractTransport
    {
        public int $attempts = 0;

        /** @var list<string> */
        public array $reached = [];

        /** @var list<string> */
        public array $broken = ['*'];

        /** @var list<array{html: string|null, text: string|null, auto: string|null}> */
        public array $bodies = [];

        protected function doSend(SentMessage $message): void
        {
            $this->attempts++;
            $to = array_map(fn ($address) => $address->getAddress(), $message->getEnvelope()->getRecipients());

            if ($this->broken === ['*'] || array_intersect($to, $this->broken) !== []) {
                throw new TransportException('The SMTP server did not answer.');
            }

            array_push($this->reached, ...$to);
            $email = $message->getOriginalMessage();
            $this->bodies[] = [
                'html' => $email->getHtmlBody(),
                'text' => $email->getTextBody(),
                'auto' => $email->getHeaders()->get('Auto-Submitted')?->getBodyAsString(),
            ];
        }

        public function __toString(): string
        {
            return 'errors-alert://';
        }
    };
    $transport->broken = $broken;

    errorsAlertLiftFake();
    Mail::extend('errors-alert', fn () => $transport);
    config(['mail.default' => 'errors-alert', 'mail.mailers.errors-alert' => ['transport' => 'errors-alert']]);

    return $transport;
}

// ---- who is told, and for what ------------------------------------------------------------------------------------

describe('the instant e-mail', function () {
    it('goes out for a brand-new server error', function (array $event) {
        errorsAlertSuper();

        expect(errorsAlertRecord(errorsAlertEvent($event)))->toBe(Recorded::Created);

        Mail::assertSent(ErrorAlertMail::class, 1);
        expect(ErrorIssue::query()->sole()->alerted_at)->not->toBeNull();
    })->with([
        'a website exception' => [['source' => 'site', 'kind' => 'exception']],
        'an API exception' => [['source' => 'api', 'kind' => 'exception', 'route' => '/api/v1/contracts']],
        'a database exception' => [['source' => 'database', 'kind' => 'exception', 'class' => 'Illuminate\Database\QueryException', 'message' => 'SQLSTATE[08006] could not connect', 'status' => null]],
        'an API answer of 503' => [['source' => 'api', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 503, 'route' => '/api/v1/contracts']],
        'a website error page of 500' => [['source' => 'site', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 500, 'route' => '/app/reports']],
    ]);

    it('stays silent for what is not a server error', function (array $event) {
        errorsAlertSuper();

        expect(errorsAlertRecord(errorsAlertEvent($event)))->toBe(Recorded::Created);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        expect(ErrorIssue::query()->sole()->alerted_at)->toBeNull();
    })->with([
        'a website 404' => [['source' => 'site', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 404, 'route' => '/blog/typo']],
        'a website 419' => [['source' => 'site', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 419, 'route' => '/app/login']],
        'a website 429' => [['source' => 'site', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 429, 'route' => '/app/login']],
        'a website 403' => [['source' => 'site', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 403, 'route' => '/admin/errors']],
        'a slow query' => [['source' => 'database', 'kind' => 'slow_query', 'class' => '', 'message' => 'select * from contracts where id = ?', 'trace' => '', 'status' => null, 'method' => null, 'route' => null]],
        'a browser script error' => [['source' => 'site', 'kind' => 'script', 'platform' => 'web', 'class' => 'TypeError', 'trace' => '', 'status' => null]],
        'a phone crash' => [['source' => 'app', 'kind' => 'crash', 'platform' => 'android', 'device' => 'mobile', 'class' => 'StateError', 'trace' => '', 'status' => null]],
    ]);

    it('decides from the issue alone: source, kind and the status in the class', function (array $issue, bool $alerts) {
        errorsAlertSuper();
        $issue = errorsAlertIssue($issue + ['first_seen_at' => now(), 'last_seen_at' => now()]);

        expect(app(ErrorAlerts::class)->instant($issue))->toBe($alerts);

        $alerts ? Mail::assertSent(ErrorAlertMail::class, 1) : Mail::assertNothingSent();
    })->with([
        'exception from the website' => [['source' => 'site', 'kind' => 'exception'], true],
        'exception from the API' => [['source' => 'api', 'kind' => 'exception'], true],
        'exception from the database' => [['source' => 'database', 'kind' => 'exception'], true],
        'http.500' => [['source' => 'site', 'kind' => 'http', 'class' => 'http.500'], true],
        'http.503' => [['source' => 'api', 'kind' => 'http', 'class' => 'http.503'], true],
        'http.599' => [['source' => 'api', 'kind' => 'http', 'class' => 'http.599'], true],
        'http.499' => [['source' => 'site', 'kind' => 'http', 'class' => 'http.499'], false],
        'http.422 from the API' => [['source' => 'api', 'kind' => 'http', 'class' => 'http.422'], false],
        'http without a class' => [['source' => 'site', 'kind' => 'http', 'class' => null], false],
        'http with a class that is no status' => [['source' => 'site', 'kind' => 'http', 'class' => 'http.five-hundred'], false],
        'an exception from a phone' => [['source' => 'app', 'kind' => 'exception'], false],
        'a script error' => [['source' => 'site', 'kind' => 'script', 'class' => 'TypeError'], false],
        'a crash' => [['source' => 'app', 'kind' => 'crash', 'class' => 'StateError'], false],
        'a slow query' => [['source' => 'database', 'kind' => 'slow_query', 'class' => null], false],
        'an ignored issue' => [['status' => 'ignored'], false],
        'a resolved issue' => [['status' => 'resolved'], false],
        'an issue already alerted' => [['alerted_at' => '2026-11-04 09:00:00'], false],
    ]);

    it('is sent once for an issue, however often it happens', function () {
        errorsAlertSuper();

        expect(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Created)
            ->and(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Counted)
            ->and(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Counted);

        Mail::assertSent(ErrorAlertMail::class, 1);
    });

    it('does not go out when a resolved issue comes back, and the digest calls it back', function () {
        errorsAlertSuper();
        $staff = User::factory()->platformAdmin('admin')->create();

        errorsAlertRecord(errorsAlertEvent());
        $issue = ErrorIssue::query()->sole();
        Mail::assertSent(ErrorAlertMail::class, 1);

        app(ErrorTriage::class)->resolve($issue, $staff);
        $this->travelTo(now()->addHours(2));

        expect(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Reopened);
        Mail::assertSent(ErrorAlertMail::class, 1);

        expect(errorsAlertDigest())->toBeTrue()
            ->and(errorsAlertLists())->toBe(['new' => [], 'cameBack' => [$issue->id], 'growing' => []]);
    });

    it('never goes out for an ignored issue, which is also left out of the digest', function () {
        errorsAlertSuper();
        $staff = User::factory()->platformAdmin('admin')->create();

        errorsAlertRecord(errorsAlertEvent());
        $issue = ErrorIssue::query()->sole();
        app(ErrorTriage::class)->ignore($issue, $staff);
        $this->travelTo(now()->addHours(2));

        expect(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Ignored)
            ->and(errorsAlertDigest())->toBeFalse();

        Mail::assertSent(ErrorAlertMail::class, 1);
        Mail::assertNotSent(ErrorDigestMail::class);
    });

    it('goes to the active super admins and nobody else', function () {
        $owner = errorsAlertSuper('en', 'owner@example.test');
        $second = errorsAlertSuper('en', 'second@example.test');
        User::factory()->platformAdmin('admin')->create(['email' => 'support@example.test']);
        User::factory()->platformAdmin('super_admin')->suspended()->create(['email' => 'gone@example.test']);
        User::factory()->create(['email' => 'customer@example.test']);

        errorsAlertRecord(errorsAlertEvent());

        Mail::assertSent(ErrorAlertMail::class, 2);
        Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $mail) => $mail->hasTo($owner->email));
        Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $mail) => $mail->hasTo($second->email));

        foreach (['support@example.test', 'gone@example.test', 'customer@example.test'] as $other) {
            Mail::assertNotSent(ErrorAlertMail::class, fn (ErrorAlertMail $mail) => $mail->hasTo($other));
        }
    });

    it('keeps the alert unsent, and takes nothing from the caps, when nobody can be told', function () {
        User::factory()->platformAdmin('admin')->create();
        User::factory()->platformAdmin('super_admin')->suspended()->create();

        errorsAlertRecord(errorsAlertEvent());

        Mail::assertNothingSent();
        expect(ErrorIssue::query()->sole()->alerted_at)->toBeNull()
            ->and(RateLimiter::attempts('error-alerts-hour'))->toBe(0)
            ->and(RateLimiter::attempts('error-alerts-day'))->toBe(0);
    });

    it('is told in the language of each super admin', function () {
        $english = errorsAlertSuper('en');
        $arabic = errorsAlertSuper('ar');
        $french = errorsAlertSuper('fr');

        errorsAlertRecord(errorsAlertEvent());

        foreach ([$english->email => 'en', $arabic->email => 'ar', $french->email => 'fr'] as $email => $language) {
            Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $mail) => $mail->hasTo($email) && $mail->locale === $language);
        }
    });

    it('is listened for once, so one new issue is one e-mail for one person', function () {
        errorsAlertSuper();
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]);

        expect(app('events')->getListeners(ErrorIssueCreated::class))->toHaveCount(1);

        event(new ErrorIssueCreated($issue));

        Mail::assertSent(ErrorAlertMail::class, 1);
    });

    it('sends nothing for an issue whose row already says it was told about', function () {
        errorsAlertSuper();
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]);
        ErrorIssue::query()->whereKey($issue->id)->update(['alerted_at' => now()->subMinute()]); // another worker was first

        expect($issue->alerted_at)->toBeNull() // the copy the event carries is stale
            ->and(app(ErrorAlerts::class)->instant($issue))->toBeFalse()
            ->and(RateLimiter::attempts('error-alerts-hour'))->toBe(0);
        Mail::assertNothingSent();
    });

    it('sends nothing when staff dealt with the issue between the event and the mail', function (string $status) {
        errorsAlertSuper();
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]);
        ErrorIssue::query()->whereKey($issue->id)->update(['status' => $status]);

        expect($issue->status)->toBe('open') // the copy the event carries is stale
            ->and(app(ErrorAlerts::class)->instant($issue))->toBeFalse()
            ->and(RateLimiter::attempts('error-alerts-hour'))->toBe(0);
        Mail::assertNothingSent();
    })->with(['resolved', 'ignored']);

    it('sends nothing for an issue that was removed in the meantime', function () {
        errorsAlertSuper();
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]);
        ErrorIssue::query()->whereKey($issue->id)->delete();

        expect(app(ErrorAlerts::class)->instant($issue))->toBeFalse();
        Mail::assertNothingSent();
    });

    it('does not even read the database for an issue that never alerts', function () {
        errorsAlertSuper();
        $quiet = errorsAlertIssue(['source' => 'site', 'kind' => 'http', 'class' => 'http.404', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $crash = errorsAlertIssue(['source' => 'app', 'kind' => 'crash', 'class' => 'StateError', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $queries = errorsAlertQueries(function () use ($quiet, $crash) {
            event(new ErrorIssueCreated($quiet));
            event(new ErrorIssueCreated($crash));
        });

        expect($queries)->toBe([]);
        Mail::assertNothingSent();
    });
});

describe('the caps', function () {
    it('sends 3 an hour over all issues and holds the fourth for the digest', function () {
        errorsAlertSuper();

        foreach (range(0, 3) as $n) {
            errorsAlertRecord(errorsAlertFailure($n));
        }

        Mail::assertSent(ErrorAlertMail::class, 3);
        $waiting = ErrorIssue::query()->whereNull('alerted_at')->sole();

        expect(ErrorIssue::query()->whereNotNull('alerted_at')->count())->toBe(3);

        expect(errorsAlertDigest())->toBeTrue()
            ->and(errorsAlertLists()['new'])->toContain($waiting->id)->toHaveCount(4);
    });

    it('counts an issue once however many people it is sent to', function () {
        errorsAlertSuper('en', 'owner@example.test');
        errorsAlertSuper('en', 'second@example.test');

        foreach (range(0, 3) as $n) {
            errorsAlertRecord(errorsAlertFailure($n));
        }

        Mail::assertSent(ErrorAlertMail::class, 6);
        expect(ErrorIssue::query()->whereNull('alerted_at')->count())->toBe(1);
    });

    it('starts again when the hour is over', function () {
        errorsAlertSuper();

        foreach (range(0, 3) as $n) {
            errorsAlertRecord(errorsAlertFailure($n));
        }
        Mail::assertSent(ErrorAlertMail::class, 3);

        $this->travelTo(now()->addMinutes(61));
        errorsAlertRecord(errorsAlertFailure(4));

        Mail::assertSent(ErrorAlertMail::class, 4);
    });

    it('sends 10 a day and holds the eleventh, then sends again when the day is over', function () {
        errorsAlertSuper();

        foreach (range(0, 10) as $n) {
            if ($n > 0 && $n % 3 === 0) {
                $this->travelTo(now()->addMinutes(61)); // a new hour, the same day
            }
            errorsAlertRecord(errorsAlertFailure($n));
        }

        Mail::assertSent(ErrorAlertMail::class, 10);
        expect(ErrorIssue::query()->whereNull('alerted_at')->count())->toBe(1);

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:31:00', 'UTC'));
        errorsAlertRecord(errorsAlertFailure(11));

        Mail::assertSent(ErrorAlertMail::class, 11);
    });

    it('counts under the two keys, for an hour and for a day', function () {
        errorsAlertSuper();

        errorsAlertRecord(errorsAlertEvent());

        expect(RateLimiter::attempts('error-alerts-hour'))->toBe(1)
            ->and(RateLimiter::attempts('error-alerts-day'))->toBe(1)
            ->and(RateLimiter::availableIn('error-alerts-hour'))->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600)
            ->and(RateLimiter::availableIn('error-alerts-day'))->toBeGreaterThan(86000)->toBeLessThanOrEqual(86400);
    });

    it('does not look for anybody to tell once a cap is reached', function () {
        errorsAlertSuper();
        foreach (range(1, 3) as $hit) {
            RateLimiter::hit('error-alerts-hour', 3600);
        }
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]);

        $queries = errorsAlertQueries(function () use ($issue) {
            expect(app(ErrorAlerts::class)->instant($issue))->toBeFalse();
        });

        expect($queries)->toBe([]);
        Mail::assertNothingSent();
    });

    it('holds the hourly cap when workers finish their issues at the same moment', function () {
        errorsAlertSuper();
        $issues = array_map(fn () => errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]), range(0, 7));
        $others = array_slice($issues, 1);

        // While one worker looks up whom to tell, the next has passed the same check: each of them, in turn, is caught between
        // reading the counters and writing them. Eight issues, one after the other nested, as eight workers would interleave.
        DB::listen(function (QueryExecuted $query) use (&$others) {
            if ($others !== [] && str_contains($query->sql, 'platform_role')) {
                app(ErrorAlerts::class)->instant(array_shift($others));
            }
        });

        app(ErrorAlerts::class)->instant($issues[0]);

        Mail::assertSent(ErrorAlertMail::class, ErrorAlerts::HOUR_LIMIT);
        expect(ErrorIssue::query()->whereNotNull('alerted_at')->count())->toBe(ErrorAlerts::HOUR_LIMIT)
            ->and(ErrorIssue::query()->whereNull('alerted_at')->count())->toBe(8 - ErrorAlerts::HOUR_LIMIT) // left for the digest
            ->and(RateLimiter::attempts('error-alerts-day'))->toBe(ErrorAlerts::HOUR_LIMIT);
    });

    it('does not take a place in the day for a mail that did not go', function () {
        $transport = errorsAlertMailer();
        errorsAlertSuper();

        foreach (range(0, 2) as $n) {
            errorsAlertRecord(errorsAlertFailure($n));
        }

        expect($transport->attempts)->toBe(3)
            ->and(RateLimiter::attempts('error-alerts-hour'))->toBe(3) // every attempt counts for the hour: a dead server is tried 3 times
            ->and(RateLimiter::attempts('error-alerts-day'))->toBe(0);
    });

    it('takes a place in the day for a mail that went', function () {
        errorsAlertSuper();

        errorsAlertRecord(errorsAlertFailure(0));
        errorsAlertRecord(errorsAlertFailure(1));

        expect(RateLimiter::attempts('error-alerts-hour'))->toBe(2)
            ->and(RateLimiter::attempts('error-alerts-day'))->toBe(2);
    });

    it('is not held up for the day by a mail server that was down all morning', function () {
        $transport = errorsAlertMailer();
        errorsAlertSuper();

        foreach (range(0, 11) as $n) { // twelve failed attempts, three an hour
            if ($n > 0 && $n % 3 === 0) {
                $this->travelTo(now()->addMinutes(61));
            }
            errorsAlertRecord(errorsAlertFailure($n));
        }
        expect($transport->attempts)->toBe(12)->and(RateLimiter::attempts('error-alerts-day'))->toBe(0);

        $transport->broken = []; // the server is back
        $this->travelTo(now()->addMinutes(61));
        errorsAlertRecord(errorsAlertFailure(12));

        expect($transport->reached)->toHaveCount(1)
            ->and(ErrorIssue::query()->whereNotNull('alerted_at')->count())->toBe(1);
    });
});

// ---- what the e-mails hold ----------------------------------------------------------------------------------------

describe('the instant e-mail holds', function () {
    it('the source, the title and the count, a link to the issue, and nothing else', function () {
        errorsAlertSuper();
        $userId = (string) Str::uuid();
        $long = 'The gateway refused sara.ali@example.com on 0501234567 because '.str_repeat('a reason that goes on and on and on ', 14);

        errorsAlertRecord(errorsAlertEvent([
            'source' => 'api',
            'message' => $long,
            'route' => '/api/v1/contracts/{contract}/payments',
            'userId' => $userId,
        ]));
        errorsAlertRecord(errorsAlertEvent(['source' => 'api', 'message' => $long, 'userId' => $userId])); // counted, no second mail

        $issue = ErrorIssue::query()->sole();
        $sample = $issue->samples()->first();
        $mail = Mail::sent(ErrorAlertMail::class)->sole();
        $html = $mail->render();

        expect($html)->toContain('API')
            ->toContain(e($issue->title))
            ->toContain('Times seen')
            ->toContain('https://qistas.example/admin/errors/'.$issue->id)
            ->and(strlen($sample->message))->toBeGreaterThan(strlen($issue->title) + 100);

        foreach ([
            $sample->message,                                       // the message body: the title is all that is told
            'app/Billing/Gateway.php', 'Gateway->charge',           // the trace
            '/api/v1/contracts', '{contract}', 'payments',          // the route
            'sara.ali@example.com', '0501234567',                   // personal data the scrubber took out
            $userId, ErrorRecorder::person($userId),                // the person
            'POST',
        ] as $secret) {
            expect($html)->not->toContain($secret);
            $mail->assertDontSeeInText($secret);
        }

        $mail->assertSeeInText($issue->title);
        $mail->assertSeeInText('https://qistas.example/admin/errors/'.$issue->id);
    });

    it('the count so far, read again when the mail goes out', function () {
        errorsAlertSuper();
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now(), 'occurrences' => 1]);
        ErrorIssue::query()->whereKey($issue->id)->update(['occurrences' => 1234]); // more sightings since the issue was opened

        expect(app(ErrorAlerts::class)->instant($issue))->toBeTrue();

        $mail = Mail::sent(ErrorAlertMail::class)->sole();
        $mail->assertSeeInText('1,234');
        expect($mail->render())->toContain('1,234');
    });

    it('a subject that names the source and the title', function () {
        errorsAlertSuper();

        errorsAlertRecord(errorsAlertEvent(['source' => 'database', 'class' => 'PDOException', 'message' => 'connection lost', 'status' => null]));

        $mail = Mail::sent(ErrorAlertMail::class)->sole();
        $mail->render();

        expect($mail->subject)->toBe('New error (Database): PDOException: connection lost');
    });

    it('the address of the settings, never the host a request came with', function () {
        errorsAlertSuper();
        URL::forceRootUrl('https://forged-host.example');

        try {
            errorsAlertRecord(errorsAlertEvent());
        } finally {
            URL::forceRootUrl(null);
        }

        $issue = ErrorIssue::query()->sole();
        $html = Mail::sent(ErrorAlertMail::class)->sole()->render();

        expect($html)->toContain('https://qistas.example/admin/errors/'.$issue->id)->not->toContain('forged-host');
    });

    it('markup in a title as text, and no link made of it', function () {
        errorsAlertSuper();

        errorsAlertRecord(errorsAlertEvent(['message' => '<script>alert(1)</script> [open the account](https://evil.example/login) <img src=x onerror=alert(1)>']));

        $html = Mail::sent(ErrorAlertMail::class)->sole()->render();

        expect($html)->not->toContain('<script>')
            ->not->toContain('<img src=x')
            ->not->toContain('href="https://evil.example')
            ->toContain('&lt;script&gt;');
    });

    it('Arabic for a super admin who reads Arabic, laid out right to left', function () {
        errorsAlertSuper('ar');

        errorsAlertRecord(errorsAlertEvent());

        $mail = Mail::sent(ErrorAlertMail::class)->sole();
        $html = $mail->render();

        expect($mail->locale)->toBe('ar')
            ->and($html)->toContain('dir="rtl"')->toContain('lang="ar"')
            ->toContain(trans('A new error has appeared', [], 'ar'))
            ->toContain(trans('Open the error', [], 'ar'))
            ->toContain(trans('Times seen', [], 'ar'))
            ->not->toContain('A new error has appeared')
            ->not->toContain('Open the error')
            ->and(trans('A new error has appeared', [], 'ar'))->not->toBe('A new error has appeared')
            ->and($mail->subject)->toContain(trans('Website', [], 'ar'))->not->toContain('New error');
        $mail->assertSeeInText(trans('A new error has appeared', [], 'ar'));
    });

    it('English and French laid out left to right', function () {
        errorsAlertSuper('en', 'owner@example.test');
        errorsAlertSuper('fr', 'patron@example.test');

        errorsAlertRecord(errorsAlertEvent());

        $english = Mail::sent(ErrorAlertMail::class, fn (ErrorAlertMail $mail) => $mail->hasTo('owner@example.test'))->sole()->render();
        $french = Mail::sent(ErrorAlertMail::class, fn (ErrorAlertMail $mail) => $mail->hasTo('patron@example.test'))->sole()->render();

        expect($english)->toContain('dir="ltr"')->toContain('lang="en"')->toContain('A new error has appeared')
            ->and($french)->toContain('dir="ltr"')->toContain('lang="fr"')->toContain(trans('A new error has appeared', [], 'fr'))
            ->not->toContain('A new error has appeared');
    });
});

describe('a mail that cannot be sent', function () {
    it('is sent through the SMTP server the admin saved, like every other e-mail', function () {
        errorsAlertSuper();
        app(MailSettings::class)->save([
            'host' => '127.0.0.1', 'port' => 1, 'security' => 'starttls', 'username' => null, 'password' => null,
            'from_address' => 'noreply@qistas.test', 'from_name' => 'Qistas',
        ], null);
        config(['mail.default' => 'array']); // as at the start of a request, before the first mail is built
        errorsAlertLiftFake();

        expect(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Created); // nothing listens on port 1: the mail fails, quietly

        $transport = Mail::mailer()->getSymfonyTransport();

        expect(config('mail.default'))->toBe('smtp')
            ->and($transport)->toBeInstanceOf(EsmtpTransport::class)
            ->and($transport->getStream()->getHost())->toBe('127.0.0.1');
    });

    it('does not stop the recorder', function () {
        $transport = errorsAlertMailer();
        errorsAlertSuper();
        Exceptions::fake();

        expect(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Created);

        $issue = ErrorIssue::query()->sole();

        expect($transport->attempts)->toBe(1)
            ->and($issue->alerted_at)->toBeNull()
            ->and($issue->occurrences)->toBe(1)
            ->and(ErrorOccurrence::query()->count())->toBe(1)
            ->and(ErrorIssue::query()->count())->toBe(1); // the failure of the mail is not an issue of its own
        Exceptions::assertNothingReported();
    });

    it('still reaches the people the server does answer for', function () {
        $transport = errorsAlertMailer(['down@example.test']);
        errorsAlertSuper('en', 'down@example.test');
        errorsAlertSuper('en', 'up@example.test');

        expect(errorsAlertRecord(errorsAlertEvent()))->toBe(Recorded::Created);

        expect($transport->reached)->toBe(['up@example.test'])
            ->and(ErrorIssue::query()->sole()->alerted_at)->not->toBeNull();
    });

    it('counts as an attempt, so a server that is down is tried 3 times an hour and no more', function () {
        $transport = errorsAlertMailer();
        errorsAlertSuper();

        foreach (range(0, 5) as $n) {
            errorsAlertRecord(errorsAlertFailure($n));
        }

        expect($transport->attempts)->toBe(3)
            ->and(ErrorIssue::query()->count())->toBe(6)
            ->and(ErrorIssue::query()->whereNotNull('alerted_at')->count())->toBe(0);
    });

    it('goes through a real mailer in HTML and in plain text, in the language of the person', function () {
        $transport = errorsAlertMailer(['nobody@example.test']);
        errorsAlertSuper('ar', 'owner@example.test');

        errorsAlertRecord(errorsAlertEvent());

        $issue = ErrorIssue::query()->sole();
        $sent = $transport->bodies[0];

        expect($transport->reached)->toBe(['owner@example.test'])
            ->and($sent['html'])->toContain('<html lang="ar" dir="rtl">')->toContain(trans('A new error has appeared', [], 'ar'))
            ->and($sent['text'])->toContain(trans('A new error has appeared', [], 'ar'))->toContain($issue->title)
            ->toContain('https://qistas.example/admin/errors/'.$issue->id)->not->toContain('<')
            ->and(app()->getLocale())->toBe('en'); // the language of the mail did not stay behind
    });

    it('marks both mails as automatic, so no out-of-office reply is sent to them', function () {
        $transport = errorsAlertMailer(['nobody@example.test']);
        errorsAlertSuper('en', 'owner@example.test');
        errorsAlertRecord(errorsAlertEvent());
        errorsAlertNew();

        expect(errorsAlertDigest())->toBeTrue()
            ->and($transport->bodies)->toHaveCount(2)
            ->and(array_column($transport->bodies, 'auto'))->toBe(['auto-generated', 'auto-generated']);
    });

    it('tells the digest too, through a real mailer', function () {
        $transport = errorsAlertMailer(['nobody@example.test']);
        errorsAlertSuper('fr', 'owner@example.test');
        $issue = errorsAlertNew(1, ['title' => 'RuntimeException: gateway refused the call']);

        expect(errorsAlertDigest())->toBeTrue();

        expect($transport->reached)->toBe(['owner@example.test'])
            ->and($transport->bodies[0]['html'])->toContain(trans('Error summary of the last 24 hours', [], 'fr'))->toContain($issue->title)
            ->and($transport->bodies[0]['text'])->toContain($issue->title)->not->toContain('<');
    });

    it('is swallowed by the digest too, which says it could not be sent', function () {
        errorsAlertMailer();
        errorsAlertSuper();
        errorsAlertNew();

        expect(errorsAlertOutcome())->toBe(DigestOutcome::Failed);
    });
});

// ---- the digest ---------------------------------------------------------------------------------------------------

describe('the digest', function () {
    it('sends nothing on a quiet day', function () {
        errorsAlertSuper();
        errorsAlertIssue(); // five days old, seen once
        errorsAlertBusy(2, 0); // busy, but not enough

        expect(errorsAlertDigest())->toBeFalse();

        Mail::assertNothingSent();
    });

    it('sends nothing when nobody can be told', function () {
        User::factory()->platformAdmin('admin')->create();
        errorsAlertNew();

        expect(errorsAlertDigest())->toBeFalse();

        Mail::assertNothingSent();
    });

    it('lists what is new in the last 24 hours, and not what is older', function () {
        errorsAlertSuper();
        $fresh = errorsAlertNew(1);
        $almost = errorsAlertNew(23.9);
        $older = errorsAlertNew(24.1);

        expect(errorsAlertDigest())->toBeTrue();

        Mail::assertSent(ErrorDigestMail::class, 1);
        expect(errorsAlertLists()['new'])->toEqualCanonicalizing([$fresh->id, $almost->id])->not->toContain($older->id);
    });

    it('lists what came back in the last 24 hours while it is open', function () {
        errorsAlertSuper();
        $back = errorsAlertBack(2);
        errorsAlertBack(25);                              // came back yesterday
        errorsAlertBack(1, ['status' => 'resolved']);     // came back and was resolved again
        errorsAlertBack(1, ['reopened_at' => null]);      // never came back

        expect(errorsAlertDigest())->toBeTrue()
            ->and(errorsAlertLists())->toBe(['new' => [], 'cameBack' => [$back->id], 'growing' => []]);
    });

    it('lists what is growing by the one rule of ErrorTrend', function (int $today, int|array $before, bool $growing) {
        errorsAlertSuper();
        $issue = errorsAlertBusy($today, $before);

        expect(errorsAlertDigest())->toBe($growing);

        if ($growing) {
            expect(errorsAlertLists())->toBe(['new' => [], 'cameBack' => [], 'growing' => [$issue->id]]);
        }
    })->with([
        'three today after a quiet week' => [3, 0, true],
        'three today against one a day' => [3, 1, true],
        'exactly double the average' => [6, 3, true],
        'exactly double a week with one busy day' => [4, [0, 0, 0, 0, 0, 0, 14], true],
        'two today is not enough' => [2, 0, false],
        'five today against three a day' => [5, 3, false],
        'three today against two a day' => [3, 2, false],
        'a busy week and a quiet today' => [1, 9, false],
    ]);

    it('judges "growing" on the clock of the digest it is asked for, not on the real one', function () {
        errorsAlertSuper();
        $then = CarbonImmutable::now()->subDays(3);

        // Busy three days ago (nine sightings, one a day the week before), quiet since.
        $days = [$then->toDateString() => 9];
        foreach (range(1, 7) as $back) {
            $days[$then->subDays($back)->toDateString()] = 1;
        }
        $issue = errorsAlertIssue(['occurrences' => 16, 'first_seen_at' => $then->subDays(10), 'last_seen_at' => $then->subHour()], $days);

        // The digest for that day lists it; today's does not (it is quiet today).
        expect(app(ErrorAlerts::class)->digest($then))->toBe(DigestOutcome::Sent)
            ->and(errorsAlertLists())->toBe(['new' => [], 'cameBack' => [], 'growing' => [$issue->id]])
            ->and(errorsAlertOutcome())->toBe(DigestOutcome::NothingToSend);
    });

    it('leaves out an issue that is ignored or resolved, in every list', function () {
        errorsAlertSuper();
        errorsAlertNew(1, ['status' => 'ignored']);
        errorsAlertNew(1, ['status' => 'resolved']);
        errorsAlertBack(1, ['status' => 'ignored']);
        errorsAlertBusy(9, 0, ['status' => 'ignored']);
        errorsAlertBusy(9, 0, ['status' => 'resolved']);

        expect(errorsAlertDigest())->toBeFalse();

        Mail::assertNothingSent();
    });

    it('lists an issue once: back before new before growing', function () {
        errorsAlertSuper();
        $both = errorsAlertBack(1, ['first_seen_at' => now()->subHours(5)]);
        $newAndBusy = errorsAlertBusy(5, 0, ['first_seen_at' => now()->subHours(3)]);
        $busy = errorsAlertBusy(4, 0);

        expect(errorsAlertDigest())->toBeTrue()
            ->and(errorsAlertLists())->toBe(['new' => [$newAndBusy->id], 'cameBack' => [$both->id], 'growing' => [$busy->id]]);
    });

    it('lists an issue the instant e-mail already told about', function () {
        errorsAlertSuper();
        errorsAlertRecord(errorsAlertEvent());
        $issue = ErrorIssue::query()->sole();

        expect($issue->alerted_at)->not->toBeNull()
            ->and(errorsAlertDigest())->toBeTrue()
            ->and(errorsAlertLists()['new'])->toBe([$issue->id]);
    });

    it('lists the issues of every source and kind, the client-reported ones too', function () {
        errorsAlertSuper();
        errorsAlertRecord(errorsAlertEvent(['source' => 'site', 'kind' => 'script', 'platform' => 'web', 'class' => 'TypeError', 'trace' => '', 'status' => null]));
        errorsAlertRecord(errorsAlertEvent(['source' => 'app', 'kind' => 'crash', 'platform' => 'android', 'device' => 'mobile', 'class' => 'StateError', 'trace' => '', 'status' => null]));
        errorsAlertRecord(errorsAlertEvent(['source' => 'site', 'kind' => 'http', 'class' => '', 'message' => '', 'trace' => '', 'status' => 404, 'route' => '/blog/typo']));
        errorsAlertRecord(errorsAlertEvent(['source' => 'database', 'kind' => 'slow_query', 'class' => '', 'message' => 'select * from contracts where id = ?', 'trace' => '', 'status' => null, 'method' => null, 'route' => null]));

        Mail::assertNothingSent();
        expect(errorsAlertDigest())->toBeTrue()
            ->and(errorsAlertLists()['new'])->toHaveCount(4);
    });

    it('is one e-mail for each active super admin, in the language of each', function () {
        $english = errorsAlertSuper('en', 'owner@example.test');
        $arabic = errorsAlertSuper('ar', 'ahmad@example.test');
        User::factory()->platformAdmin('admin')->create(['email' => 'support@example.test']);
        User::factory()->platformAdmin('super_admin')->suspended()->create(['email' => 'gone@example.test']);
        errorsAlertNew();

        expect(errorsAlertDigest())->toBeTrue();

        Mail::assertSent(ErrorDigestMail::class, 2);
        Mail::assertSent(ErrorDigestMail::class, fn (ErrorDigestMail $mail) => $mail->hasTo($english->email) && $mail->locale === 'en');
        Mail::assertSent(ErrorDigestMail::class, fn (ErrorDigestMail $mail) => $mail->hasTo($arabic->email) && $mail->locale === 'ar');
        foreach (['support@example.test', 'gone@example.test'] as $other) {
            Mail::assertNotSent(ErrorDigestMail::class, fn (ErrorDigestMail $mail) => $mail->hasTo($other));
        }
    });

    it('names each issue by source, title, count and link, and sets out only the lists that have something', function () {
        errorsAlertSuper();
        $new = errorsAlertNew(1, ['source' => 'api', 'title' => 'RuntimeException: gateway refused the call', 'occurrences' => 41]);
        $back = errorsAlertBack(2, ['source' => 'database', 'title' => 'PDOException: connection lost', 'occurrences' => 7]);

        expect(errorsAlertDigest())->toBeTrue();

        $mail = Mail::sent(ErrorDigestMail::class)->sole();
        $html = $mail->render();

        expect($html)->toContain('Error summary of the last 24 hours')
            ->toContain('New in the last 24 hours')->toContain('Came back in the last 24 hours')
            ->not->toContain('Growing')
            ->toContain('API')->toContain('Database')
            ->toContain(e($new->title))->toContain(e($back->title))
            ->toContain('41')->toContain('7')
            ->toContain('https://qistas.example/admin/errors/'.$new->id)
            ->toContain('https://qistas.example/admin/errors/'.$back->id)
            ->toContain('https://qistas.example/admin/errors');
        $mail->assertSeeInText($new->title);
        $mail->assertSeeInText('https://qistas.example/admin/errors/'.$back->id);
        $mail->render();
        expect($mail->subject)->toBe('Error summary of the last 24 hours');
    });

    it('holds no trace, message, route or person', function () {
        errorsAlertSuper();
        $userId = (string) Str::uuid();
        errorsAlertRecord(errorsAlertEvent(['message' => 'Refused sara.ali@example.com '.str_repeat('and a reason that goes on ', 12), 'userId' => $userId]));

        expect(errorsAlertDigest())->toBeTrue();

        $sample = ErrorIssue::query()->sole()->samples()->first();
        $mail = Mail::sent(ErrorDigestMail::class)->sole();
        $html = $mail->render();

        foreach ([$sample->message, 'app/Billing/Gateway.php', 'Gateway->charge', '/app/contracts', '{contract}', 'sara.ali@example.com', $userId, ErrorRecorder::person($userId)] as $secret) {
            expect($html)->not->toContain($secret);
            $mail->assertDontSeeInText($secret);
        }
    });

    it('shows the first 25 of a long list, says how many more there are and links to all of them', function () {
        errorsAlertSuper();
        foreach (range(1, 30) as $n) {
            errorsAlertNew(1, ['title' => 'Failure '.errorsAlertWord($n), 'occurrences' => $n]);
        }

        expect(errorsAlertDigest())->toBeTrue();

        $mail = Mail::sent(ErrorDigestMail::class)->sole();

        expect($mail->new)->toHaveCount(25)
            ->and($mail->more['new'])->toBe(5)
            ->and($mail->new[0]->occurrences)->toBe(30) // the busiest first
            ->and($mail->render())->toContain('And 5 more.');
    });

    // A bot that probes for made-up addresses opens one issue per address, each seen once. They must not push the one real
    // failure (an exception, or an error page of 500 or more) below the cap of 25 lines, where it would only be "And N more".
    it('lists the exceptions and the 5xx pages of the new issues before the rest, however often the rest was seen', function () {
        errorsAlertSuper();
        $noise = collect(range(1, 30))->map(fn (int $n) => errorsAlertNew(1, [
            'kind' => 'http', 'class' => 'http.404', 'title' => 'Error 404 on /probe-'.errorsAlertWord($n), 'occurrences' => 1,
        ]));
        $busyNoise = errorsAlertNew(1, ['kind' => 'http', 'class' => 'http.404', 'title' => 'Error 404 on /old-link', 'occurrences' => 400]);
        $exception = errorsAlertNew(1, ['title' => 'RuntimeException: gateway refused', 'occurrences' => 1]);
        $page = errorsAlertNew(1, ['kind' => 'http', 'class' => 'http.502', 'title' => 'Error 502 on /app/reports', 'occurrences' => 3]);
        $busyException = errorsAlertNew(1, ['title' => 'PDOException: server has gone away', 'class' => 'PDOException', 'occurrences' => 9]);
        $slow = errorsAlertNew(1, ['source' => 'database', 'kind' => 'slow_query', 'title' => 'A slow query', 'occurrences' => 50]);

        expect(errorsAlertDigest())->toBeTrue();

        $mail = Mail::sent(ErrorDigestMail::class)->sole();
        $order = collect($mail->new)->pluck('id')->all();

        // Server errors first, the busiest of them first; then everything else, the busiest first (the one-hit 404s, all
        // equal, come last and in no order that matters).
        expect(array_slice($order, 0, 3))->toBe([$busyException->id, $page->id, $exception->id])
            ->and(array_slice($order, 3, 2))->toBe([$busyNoise->id, $slow->id])
            ->and(array_diff(array_slice($order, 5), $noise->pluck('id')->all()))->toBe([])
            ->and($mail->new)->toHaveCount(25)
            ->and($mail->more['new'])->toBe(10); // 35 new issues, 25 of them listed
    });

    it('puts a rarely seen exception ahead of a busier 404, each sort busiest first', function () {
        errorsAlertSuper();
        $quiet = errorsAlertNew(1, ['title' => 'Quiet failure', 'occurrences' => 2]);
        $loud = errorsAlertNew(1, ['title' => 'Loud failure', 'occurrences' => 20]);
        $bots = [
            errorsAlertNew(1, ['kind' => 'http', 'class' => 'http.404', 'title' => 'Error 404 on /a', 'occurrences' => 1]),
            errorsAlertNew(1, ['kind' => 'http', 'class' => 'http.404', 'title' => 'Error 404 on /b', 'occurrences' => 5]),
        ];

        expect(errorsAlertDigest())->toBeTrue();

        expect(collect(Mail::sent(ErrorDigestMail::class)->sole()->new)->pluck('id')->all())->toBe([$loud->id, $quiet->id, $bots[1]->id, $bots[0]->id]);
    });

    it('is Arabic for a super admin who reads Arabic, laid out right to left', function () {
        errorsAlertSuper('ar');
        errorsAlertNew();
        errorsAlertBack();
        errorsAlertBusy(5, 0);

        expect(errorsAlertDigest())->toBeTrue();

        $mail = Mail::sent(ErrorDigestMail::class)->sole();
        $html = $mail->render();

        expect($html)->toContain('dir="rtl"')->toContain('lang="ar"')
            ->toContain(trans('New in the last 24 hours', [], 'ar'))
            ->toContain(trans('Came back in the last 24 hours', [], 'ar'))
            ->toContain(trans('Growing', [], 'ar'))
            ->not->toContain('New in the last 24 hours')
            ->and($mail->subject)->toBe(trans('Error summary of the last 24 hours', [], 'ar'))
            ->and($mail->subject)->not->toBe('Error summary of the last 24 hours');
    });

    it('does not list as new an issue staff already resolved, and it cannot make a digest alone', function () {
        errorsAlertSuper();
        $resolved = errorsAlertNew(2, ['status' => 'resolved']);

        expect(errorsAlertOutcome())->toBe(DigestOutcome::NothingToSend);
        Mail::assertNothingSent();

        $open = errorsAlertNew(1);

        expect(errorsAlertOutcome())->toBe(DigestOutcome::Sent)
            ->and(errorsAlertLists()['new'])->toBe([$open->id])->not->toContain($resolved->id);
    });

    it('says whether it went, had nothing to send, or could not be sent', function () {
        errorsAlertSuper();

        expect(errorsAlertOutcome())->toBe(DigestOutcome::NothingToSend);

        errorsAlertNew();

        expect(errorsAlertOutcome())->toBe(DigestOutcome::Sent);

        User::query()->update(['status' => 'suspended']); // nobody to tell is not a failed mail

        expect(errorsAlertOutcome())->toBe(DigestOutcome::NothingToSend);
    });

    it('never prints what a stranger wrote: a report from a browser or a phone is a label and a link', function () {
        errorsAlertSuper();
        $hostile = 'Your account is locked, open https://evil.example/login';
        $script = errorsAlertNew(1, ['source' => 'site', 'kind' => 'script', 'class' => 'TypeError', 'title' => $hostile, 'occurrences' => 4]);
        $crash = errorsAlertNew(1, ['source' => 'app', 'kind' => 'crash', 'class' => 'StateError', 'title' => $hostile.' now', 'occurrences' => 3]);

        expect(errorsAlertDigest())->toBeTrue();

        $mail = Mail::sent(ErrorDigestMail::class)->sole();
        $html = $mail->render();

        expect($html)->not->toContain('evil.example')->not->toContain('account is locked')
            ->toContain('Reported by a browser')->toContain('Reported by a phone')
            ->toContain('Script error')->toContain('Crash')
            ->toContain('https://qistas.example/admin/errors/'.$script->id)
            ->toContain('https://qistas.example/admin/errors/'.$crash->id)
            ->toContain('Times seen: 4');
        $mail->assertDontSeeInText('evil.example');
        $mail->assertDontSeeInText('account is locked');
        $mail->assertSeeInText('Reported by a browser');
        $mail->assertSeeInText('Reported by a phone');
        $mail->assertSeeInText('https://qistas.example/admin/errors/'.$crash->id);
    });

    it('marks a page that was not found as one, since the visitor chose the address', function () {
        errorsAlertSuper();
        $missing = errorsAlertNew(1, ['source' => 'site', 'kind' => 'http', 'class' => 'http.404', 'title' => '404 Not Found: /your-account-is-locked']);
        $broken = errorsAlertNew(1, ['source' => 'site', 'kind' => 'http', 'class' => 'http.500', 'title' => '500 Internal Server Error: /app/reports']);

        expect(errorsAlertDigest())->toBeTrue();

        $mail = Mail::sent(ErrorDigestMail::class)->sole();
        $html = $mail->render();

        expect($html)->toContain('404 Not Found: /your-account-is-locked')->toContain('500 Internal Server Error: /app/reports')
            ->and(substr_count($html, 'Page not found'))->toBe(1); // on the 404 only
        $mail->assertSeeInText('Page not found');
        $mail->assertSeeInText('404 Not Found: /your-account-is-locked');
        expect(collect($mail->new)->pluck('id')->all())->toEqualCanonicalizing([$missing->id, $broken->id]);
    });

    it('names the real limits in its footer and its hint, in every language', function (string $locale) {
        errorsAlertSuper();
        $issue = errorsAlertIssue(['first_seen_at' => now(), 'last_seen_at' => now()]);
        errorsAlertBusy(5, 0);
        errorsAlertDigest();
        $alert = (new ErrorAlertMail($issue))->locale($locale);
        $digest = Mail::sent(ErrorDigestMail::class)->sole()->locale($locale);
        $footer = trans('You receive this because you are a super admin. At most :hour of these are sent an hour and :day a day; the others wait for the daily summary.', ['hour' => ErrorAlerts::HOUR_LIMIT, 'day' => ErrorAlerts::DAY_LIMIT], $locale);
        $hint = trans('Today at least :times times, and at least double the daily average of the :days days before.', ['times' => ErrorTrend::GROWING_MIN, 'days' => ErrorTrend::WINDOW], $locale);

        expect($alert->render())->toContain(e($footer))->not->toContain(':hour')->not->toContain(':day')
            ->and($digest->render())->toContain(e($hint))->not->toContain(':times')->not->toContain(':days')
            ->and($footer)->toContain((string) ErrorAlerts::HOUR_LIMIT)->toContain((string) ErrorAlerts::DAY_LIMIT)
            ->and($hint)->toContain((string) ErrorTrend::GROWING_MIN)->toContain((string) ErrorTrend::WINDOW);
    })->with(['en', 'ar', 'fr', 'es', 'ur']);

    it('is run by a command that says what it did', function () {
        $this->artisan('qistas:errors:digest')->expectsOutputToContain('Nothing to send.')->assertExitCode(0);
        Mail::assertNothingSent();

        errorsAlertSuper();
        errorsAlertNew();

        $this->artisan('qistas:errors:digest')->expectsOutputToContain('Digest sent.')->assertExitCode(0);
        Mail::assertSent(ErrorDigestMail::class, 1);
    });

    it('fails the command, and says why, when no mail could be sent', function () {
        errorsAlertMailer();
        errorsAlertSuper();
        errorsAlertNew();

        $this->artisan('qistas:errors:digest')->expectsOutputToContain('The digest could not be sent.')->assertExitCode(1);
    });

    // The scheduler runs the command in a process of its own, so what it prints goes nowhere, CronRunner records the run as
    // fine (an exit code is not an exception, so ScheduledTaskFailed never fires), and a digest that never arrived would be
    // lost without a trace. The scheduled event writes the line to the application log itself.
    it('leaves a line in the application log when the scheduled digest ends with a failure, and none when it went well', function () {
        Log::spy();
        $digest = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'qistas:errors:digest'))->sole();

        $digest->finish(app(), 0);
        Log::shouldNotHaveReceived('warning');

        $digest->finish(app(), 1);
        Log::shouldHaveReceived('warning')->once()->with('The error digest could not be sent.');
    });
});
