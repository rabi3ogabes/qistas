<?php

declare(strict_types=1);

namespace App\Errors;

use App\Http\ApiException;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;
use WeakMap;

use function Illuminate\Support\defer;

/**
 * What breaks on the server becomes an error event here (design section 3), from three hooks: the exception handler's
 * report callback (bootstrap/app.php), the error pages a visitor is shown (RecordErrorPages) and slow queries (the
 * QueryExecuted listener of ErrorServiceProvider). It decides WHAT is recorded; ErrorRecorder decides how.
 *
 * What is recorded:
 *   - an exception the framework reports: source `database` for a failure of the database wherever it hides in the chain
 *     (DatabaseFailure, whose words for it never include the database's own message), on any request, command or job;
 *     else `api` on a request under /api, else `site`. An ApiException below 500 is an expected answer and is not an
 *     error. A queued job's failure is the job's, whether a worker of its own or the /internal/cron request ran it, and so
 *     is a scheduled task's: no route, no method, no status. A job tried three times is three sightings of its issue.
 *   - an error page: `site/http` for 403, 404, 419, 429, 500 and 503, `api/http` for any 5xx; a 404, 403 or 405 for a
 *     path only a scanner (or a browser's housekeeping) asks for is counted as scanner noise instead; a 5xx that carries
 *     the very exception that was recorded is not a second issue; a 5xx while the site is down for maintenance is not
 *     recorded; whatever the two reporters (`/client-errors`, `/api/v1/client-errors`) answer is only counted as dropped,
 *     so a stranger can never make issues by posting to them. The same page below 500 again within REPEAT_SECONDS is only
 *     counted as dropped: a hot broken link costs one cheap statement a hit, not a locked write.
 *   - a query at or above qistas.errors.slow_query_ms (3000 when that is under 1 ms), never one on the error center's
 *     own tables or one made while the capture is at work.
 *
 * Rules that hold whatever happens:
 *   - Nothing heavy runs before the answer. A hook only notes plain values (an ErrorEvent: strings and numbers, never
 *     the request, the response or the exception) and returns; scrubbing, fingerprinting and every write run after the
 *     answer, in flush(), which defer(...)->always() calls once the response is out or the command has finished. A queue
 *     worker calls it between jobs (its Looping event comes before it throws the job's scope away, which would lose what
 *     the job's failure noted: the worker reports a failure only after the job's deferred work has run) and as it stops;
 *     the application's terminating callback calls it for a command that threw. So nothing runs inside the caller's
 *     database transaction either.
 *   - Every Throwable is swallowed, and never reported: a report would come straight back here. While flush() works,
 *     capturing() is true and every hook stands back, so a failure inside the error center can never loop.
 *   - A fingerprint is recorded at most once per request (spec 8), or once per command or job when there is no request.
 *     At most PENDING_MAX events wait for one answer; the rest are counted as dropped, once per failure (not once per
 *     repeat of it), so a runaway loop cannot fill the memory and is still visible.
 *   - QISTAS_ERRORS=false: nothing is noted and nothing is deferred.
 *
 * Bound as a scoped instance (ErrorServiceProvider): one per request, and one per job in a queue worker.
 */
final class ErrorCapture
{
    /** The request attribute holding the exceptions recorded for it, so the 5xx page one of them made is not a second issue. */
    public const RECORDED = 'errors.recorded';

    /** The route of a 5xx on a path no route serves: one issue for all of them, never one per path (a flood of alerts). */
    public const UNMATCHED = '/{unmatched}';

    /** The error pages of the website that are recorded (spec 3). On the API it is every 5xx. */
    public const SITE_PAGES = [403, 404, 419, 429, 500, 503];

    /** The answers that, for a path only a scanner asks for, are scanner noise. */
    public const SCANNER_STATUSES = [403, 404, 405];

    /** The two reporters (design section 9): anyone may post to them, so what they answer is never an issue. */
    public const REPORTER_ROUTES = ['client-errors.store', 'api.client-errors.store'];

    public const REPORTER_PATHS = ['/client-errors', '/api/v1/client-errors'];

    /** Events that may wait for one answer (or one command or job); any more are counted as dropped. */
    public const PENDING_MAX = 100;

    /** How long the same error page below 500 is held back after a sighting: within it, a repeat is only counted. */
    public const REPEAT_SECONDS = 5;

    /** The threshold when qistas.errors.slow_query_ms is under 1 ms: an empty or mistyped setting is 0 (ruling R9). */
    public const SLOW_QUERY_MS = 3000.0;

    /** The request attribute that holds the fingerprints recorded for that request. */
    private const SEEN = 'errors.seen';

    /** The request attributes saying a queue worker or a scheduled task is at work inside the request, and their failures. */
    private const AT_WORK = 'errors.at-work';

    private const WORK_FAILURES = 'errors.work-failures';

    /** Fingerprints remembered outside a request before the list starts again (a long command). */
    private const SEEN_MAX = 1000;

    /** Different failures counted as dropped past PENDING_MAX; past that, nothing more is looked at. */
    private const OVERFLOW_MAX = 1000;

    /** Frames of a trace that are kept (the scrubber keeps 8 KB of them, so far fewer reach the table). */
    private const FRAMES = 100;

    /** Bytes of an exception's message that are kept before scrubbing (the scrubber reads far less). */
    private const MESSAGE_BYTES = 16384;

    /** The name of the deferred flush: Laravel keeps one of each name. */
    private const DEFERRED = 'errors.capture';

    /** The error center's own tables (ruling M10): a slow query on one of them is never recorded. */
    private const OWN_TABLES = '~\berror_(?:issues|occurrences|issue_days|issue_people|noise_days)\b~i';

    private static bool $capturing = false;

    /** @var list<Closure(): mixed> what waits for the answer */
    private array $pending = [];

    /** @var array<string, true> the events waiting, by their fields: an exact repeat is not kept twice */
    private array $pendingKeys = [];

    /** @var array<string, true> the failures past PENDING_MAX, by their class and message as fingerprinted */
    private array $overflowKeys = [];

    /** @var array<string, true> the same, by their class and message as given (so a repeat costs nothing to recognise) */
    private array $overflowSeen = [];

    /** Other work that did not fit in PENDING_MAX. */
    private int $overflowWork = 0;

    /** @var array<string, true> the fingerprints recorded when there is no request (a command, a job) */
    private array $seen = [];

    /** Is the capture at work (recording what it noted)? Every hook stands back while it is. */
    public static function capturing(): bool
    {
        return self::$capturing;
    }

    /**
     * Runs $work after the answer (see the class comment), inside the guard: every Throwable it throws is swallowed, and
     * while it runs capturing() is true, so nothing it causes is captured again. Nothing is deferred when capture is
     * off or already at work.
     */
    public static function guarded(Closure $work): void
    {
        try {
            if (self::$capturing || ! ErrorRecorder::enabled()) {
                return;
            }

            $capture = app(self::class);

            if (! $capture->later($work)) {
                $capture->overflowWork++;
            }
        } catch (Throwable) {
            // nothing to record it with: it is lost, never reported
        }
    }

    /** The exception handler's report callback: exception(), with even the capture layer's making inside the guard. */
    public static function reported(Throwable $e): void
    {
        try {
            if (self::$capturing || ErrorRecorder::busy() || ! ErrorRecorder::enabled()) {
                return;
            }

            app(self::class)->exception($e);
        } catch (Throwable) {
            // never break the report it was called from
        }
    }

    /** The slow-query threshold a setting stands for: anything that is not a number of at least 1 is 3000 (ruling R9). */
    public static function slowQueryMs(mixed $setting): float
    {
        return is_numeric($setting) && (float) $setting >= 1 ? (float) $setting : self::SLOW_QUERY_MS;
    }

    /**
     * A queue worker or a scheduled task starts inside the request being answered (the /internal/cron request runs both):
     * until it ends, what it reports is its own, not the request's.
     */
    public static function workStarts(): void
    {
        try {
            $request = self::currentRequest();
            $request?->attributes->set(self::AT_WORK, self::atWork($request) + 1);
        } catch (Throwable) {
            // the failure is then the request's: a route too many, never a failure of its own
        }
    }

    /**
     * The worker or the task has ended. A scheduled task that failed is reported just after this, so $failure is kept
     * (without keeping it alive) to be told apart from the request's own.
     */
    public static function workEnds(?Throwable $failure = null): void
    {
        try {
            $request = self::currentRequest();

            if ($request === null) {
                return;
            }

            $request->attributes->set(self::AT_WORK, max(0, self::atWork($request) - 1));

            if ($failure !== null) {
                self::remember($request, self::WORK_FAILURES, $failure);
            }
        } catch (Throwable) {
            // as above
        }
    }

    /**
     * The exception handler reported $e. $request is the request it belongs to (looked up when not given); none in a
     * command, in a job, or in a scheduled task.
     */
    public function exception(Throwable $e, ?Request $request = null): void
    {
        try {
            if (self::$capturing || ErrorRecorder::busy() || ! ErrorRecorder::enabled()) {
                return;
            }

            // A refusal the API answers in its usual shape (401, 403, 409, 410, 422) is an answer, not an error.
            if ($e instanceof ApiException && $e->status < 500) {
                return;
            }

            $request ??= self::attributed($e);

            if ($request !== null) {
                self::remember($request, self::RECORDED, $e);
            }

            $this->pend(self::exceptionEvent($e, $request));
        } catch (Throwable) {
            // never break the report it was called from
        }
    }

    /**
     * A visitor was answered with $status (RecordErrorPages calls this once the answer is out, for 403 and above), made
     * from $exception when the response says so.
     */
    public function errorPage(Request $request, int $status, ?Throwable $exception = null): void
    {
        try {
            if (self::$capturing || ! ErrorRecorder::enabled()) {
                return;
            }

            if (in_array($status, self::SCANNER_STATUSES, true) && ErrorFingerprint::isScannerPath($request->getPathInfo())) {
                $this->noise('scanner');

                return;
            }

            $api = self::underApi($request);

            if ($api ? $status < 500 || $status > 599 : ! in_array($status, self::SITE_PAGES, true)) {
                return;
            }

            // A forged origin or a throttled address at a reporter is a stranger's doing: counted, never an issue.
            if (self::reporter($request)) {
                $this->noise('dropped');

                return;
            }

            // The exception behind it is the issue already: one failure, one issue.
            if ($status >= 500 && $exception !== null && self::remembered($request, self::RECORDED, $exception)) {
                return;
            }

            // A planned page while the site is down for maintenance is no failure (and one per path would flood the alerts).
            if ($status >= 500 && app()->isDownForMaintenance()) {
                return;
            }

            $this->pend(new ErrorEvent(
                source: $api ? 'api' : 'site',
                kind: 'http',
                status: $status,
                method: $request->getMethod(),
                route: self::route($request, $status),
                userId: self::userId(),
            ));
        } catch (Throwable) {
            // never break the end of a request
        }
    }

    /** A query finished (the provider's listener calls this only for one at or above the threshold). */
    public function slowQuery(QueryExecuted $query): void
    {
        try {
            if (self::$capturing || ErrorRecorder::busy() || ! ErrorRecorder::enabled()) {
                return;
            }

            if ((float) $query->time < self::slowQueryMs(config('qistas.errors.slow_query_ms')) || preg_match(self::OWN_TABLES, $query->sql) === 1) {
                return;
            }

            $request = self::attributed();

            // The statement as it was sent, with its placeholders; the bindings are never read.
            $this->pend(new ErrorEvent(
                source: 'database',
                kind: 'slow_query',
                message: self::statement($query->sql),
                method: $request?->getMethod(),
                route: $request === null ? null : self::route($request, null),
                userId: $request === null ? null : self::userId(),
            ));
        } catch (Throwable) {
            // never break a query
        }
    }

    /**
     * Records what was noted: each event scrubbed, fingerprinted, and handed to the recorder unless its fingerprint was
     * recorded already in this request (or command, or job). Runs after the answer (defer, the worker's loop, or the
     * terminating callback); never throws.
     */
    public function flush(): void
    {
        if (self::$capturing) {
            return;
        }

        self::$capturing = true;

        try {
            $this->pendingKeys = [];

            while (($work = array_shift($this->pending)) !== null) {
                try {
                    $work();
                } catch (Throwable) {
                    // swallowed, and never reported: a report would only come back here
                }
            }

            $dropped = count($this->overflowKeys) + $this->overflowWork;
            $this->overflowKeys = [];
            $this->overflowSeen = [];
            $this->overflowWork = 0;

            if ($dropped > 0) {
                try {
                    app(ErrorRecorder::class)->noise('dropped', null, $dropped);
                } catch (Throwable) {
                    // a lost count is not an error of its own
                }
            }
        } finally {
            self::$capturing = false;
        }
    }

    /** Notes $work for after the answer; false when PENDING_MAX are waiting already. */
    private function later(Closure $work): bool
    {
        if (count($this->pending) >= self::PENDING_MAX) {
            return false;
        }

        $this->pending[] = $work;

        // `always`: Laravel skips deferred work after an answer of 400 or more, which is when there is most to record.
        defer(fn () => $this->flush(), self::DEFERRED)->always();

        return true;
    }

    /** Adds one to the day's noise of $kind, after the answer. */
    private function noise(string $kind): void
    {
        if (! $this->later(static fn () => app(ErrorRecorder::class)->noise($kind))) {
            $this->overflowWork++;
        }
    }

    /**
     * Notes an event for after the answer, unless the very same one is waiting already. Past PENDING_MAX it is only
     * counted, once per failure: the class and the message as the fingerprint reads them (scrubbed and normalised, so
     * `Payment 1 failed` and `Payment 2 failed` are one), for at most OVERFLOW_MAX different ones.
     */
    private function pend(ErrorEvent $event): void
    {
        $full = count($this->pending) >= self::PENDING_MAX;
        $given = $full ? sha1($event->source."\0".$event->kind."\0".$event->class."\0".$event->message) : '';

        // Past the limit, a failure counted already (or any failure, once OVERFLOW_MAX are) costs nothing more.
        if ($full && (isset($this->overflowSeen[$given]) || count($this->overflowSeen) >= self::OVERFLOW_MAX)) {
            return;
        }

        $key = sha1(implode("\0", [
            $event->source, $event->kind, $event->class, $event->message, $event->trace,
            (string) $event->status, (string) $event->method, (string) $event->route, (string) $event->userId,
        ]));

        if (isset($this->pendingKeys[$key])) {
            return; // the very same one waits already, and will be recorded
        }

        if ($full) {
            $this->overflowSeen[$given] = true;
            $this->overflowKeys[sha1(implode("\0", [
                $event->source, $event->kind, $event->class, (string) $event->status, (string) $event->route,
                ErrorFingerprint::normalise(ErrorScrubber::text(mb_strcut($event->message, 0, 1024, 'UTF-8'))),
            ]))] = true;

            return;
        }

        $this->pendingKeys[$key] = true;
        $this->later(fn () => $this->store($event));
    }

    /**
     * After the answer: scrubbed, fingerprinted, and recorded when its fingerprint is new in this request. An error page
     * below 500 (any visitor can call one up again and again) is recorded at most once per REPEAT_SECONDS; a repeat within
     * that only adds to the day's dropped count. An exception or a 5xx is never held back.
     */
    private function store(ErrorEvent $event): void
    {
        $scrubbed = ErrorScrubber::event($event);
        $fingerprint = ErrorFingerprint::of($scrubbed);

        if (! $this->firstTime($fingerprint)) {
            return;
        }

        if ($scrubbed->kind === 'http' && ($scrubbed->status ?? 0) < 500 && ! self::firstInWindow($fingerprint)) {
            app(ErrorRecorder::class)->noise('dropped');

            return;
        }

        app(ErrorRecorder::class)->record($scrubbed);
    }

    /**
     * Is this the first time $fingerprint is recorded in this request? The fingerprints are kept on the request (so the
     * next request, even in the same process, starts with none); with no request, on this instance, which a queue worker
     * makes anew for every job.
     */
    private function firstTime(string $fingerprint): bool
    {
        $request = self::attributed();

        if ($request !== null) {
            $seen = $request->attributes->get(self::SEEN);
            $seen = is_array($seen) ? $seen : [];

            if (isset($seen[$fingerprint])) {
                return false;
            }

            $seen[$fingerprint] = true;
            $request->attributes->set(self::SEEN, $seen);

            return true;
        }

        if (isset($this->seen[$fingerprint])) {
            return false;
        }

        if (count($this->seen) >= self::SEEN_MAX) {
            $this->seen = [];
        }

        $this->seen[$fingerprint] = true;

        return true;
    }

    /** Is this the first sighting of $fingerprint in REPEAT_SECONDS? A cache that cannot say lets it through (fails open). */
    private static function firstInWindow(string $fingerprint): bool
    {
        try {
            return Cache::add('errors:hit:'.$fingerprint, 1, self::REPEAT_SECONDS);
        } catch (Throwable) {
            return true;
        }
    }

    // ---- what an event holds -------------------------------------------------------------------------------------

    private static function exceptionEvent(Throwable $e, ?Request $request): ErrorEvent
    {
        $database = DatabaseFailure::in($e);
        $subject = $database ?? $e;
        $status = $e instanceof ApiException ? $e->status : 500;

        return new ErrorEvent(
            source: $database !== null ? 'database' : ($request !== null && self::underApi($request) ? 'api' : 'site'),
            kind: 'exception',
            class: $subject::class,
            message: $database !== null ? DatabaseFailure::message($database) : self::cut($e->getMessage()),
            trace: self::trace($subject),
            status: $request === null ? null : $status,
            method: $request?->getMethod(),
            route: $request === null ? null : self::route($request, $status),
            userId: $request === null ? null : self::userId(),
        );
    }

    /**
     * Where it was thrown and the frames of the call stack, without their arguments (they can hold anything that was
     * passed to a function): `#0 app/Foo.php(12): App\Foo->bar()`, the shape the fingerprint reads.
     */
    private static function trace(Throwable $e): string
    {
        $frames = $e->getTrace();
        $lines = ['at '.$e->getFile().'('.$e->getLine().')'];

        foreach (array_slice($frames, 0, self::FRAMES) as $number => $frame) {
            $file = $frame['file'] ?? null;
            $where = is_string($file) && $file !== '' ? $file.'('.($frame['line'] ?? 0).')' : '[internal function]';
            $lines[] = '#'.$number.' '.$where.': '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()';
        }

        $lines[] = '#'.min(count($frames), self::FRAMES).' {main}';

        return implode("\n", $lines);
    }

    /**
     * The route of the request: the pattern of the route that matched (`/app/customers/{customer}`), never the address.
     * When none did (the fallback route only turns an unknown address into a 404), the path with its numbers and ids
     * replaced for an error page below 500 (a broken link shows up by its path), one fixed pattern for a 5xx (so a
     * failure before routing is one issue, not one per path), and none for a slow query.
     */
    private static function route(Request $request, ?int $status): ?string
    {
        $route = $request->route();

        if ($route instanceof Route && ! $route->isFallback) {
            return '/'.ltrim($route->uri(), '/');
        }

        if ($status === null) {
            return null;
        }

        return $status >= 500 ? self::UNMATCHED : ErrorFingerprint::routePattern($request->getPathInfo());
    }

    private static function underApi(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $path === '/api' || str_starts_with($path, '/api/');
    }

    /** Is this a request to one of the two reporters, by the route it matched or, when it matched none, by its path? */
    private static function reporter(Request $request): bool
    {
        $route = $request->route();

        return ($route instanceof Route && in_array($route->getName(), self::REPORTER_ROUTES, true))
            || in_array(rtrim($request->getPathInfo(), '/'), self::REPORTER_PATHS, true);
    }

    /**
     * The id of the person signed in, if the request already knows who they are. Never asked for: that could mean a query
     * (to read the session's user) on a request that is failing, perhaps because the database is.
     */
    private static function userId(): ?string
    {
        try {
            $app = app();

            if (! $app->resolved('auth')) {
                return null;
            }

            $guard = $app->make('auth')->guard();
            $id = $guard->hasUser() ? $guard->id() : null;

            return is_int($id) || is_string($id) ? (string) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The request a failure belongs to: the one being answered, unless a queue worker or a scheduled task is at work
     * inside it (what they report is theirs), or $e is the failure of a scheduled task that has just ended.
     */
    private static function attributed(?Throwable $e = null): ?Request
    {
        $request = self::currentRequest();

        if ($request === null || self::atWork($request) > 0) {
            return null;
        }

        return $e !== null && self::remembered($request, self::WORK_FAILURES, $e) ? null : $request;
    }

    private static function atWork(Request $request): int
    {
        $depth = $request->attributes->get(self::AT_WORK);

        return is_int($depth) ? $depth : 0;
    }

    /** Notes $e on the request under $attribute, in a WeakMap, so the request never keeps an exception alive. */
    private static function remember(Request $request, string $attribute, Throwable $e): void
    {
        $known = $request->attributes->get($attribute);

        if (! $known instanceof WeakMap) {
            $known = new WeakMap;
            $request->attributes->set($attribute, $known);
        }

        $known[$e] = true;
    }

    private static function remembered(Request $request, string $attribute, Throwable $e): bool
    {
        $known = $request->attributes->get($attribute);

        return $known instanceof WeakMap && isset($known[$e]);
    }

    /**
     * The request the HTTP kernel is answering right now, or null (a command, a job in a worker, or after the answer has
     * been handled). Not just the container's `request`: a console process has a made-up one.
     */
    private static function currentRequest(): ?Request
    {
        try {
            $app = app();

            // The kernel stamps the moment it starts on a request and clears it when the request has ended.
            if (! $app->resolved(HttpKernel::class) || $app->make(HttpKernel::class)->requestStartedAt() === null) {
                return null;
            }

            return $app->bound('request') ? $app->make('request') : null;
        } catch (Throwable) {
            return null; // a kernel of another kind knows no such moment: then there is no request to tell
        }
    }

    /**
     * A statement with every value written into it masked too (`limit 10`, `where x < 200000`, a quoted name in raw SQL),
     * whatever it starts with: SqlMasker believes a text is SQL only when it starts the way a statement it knows does
     * (`with recursive c(x) as (` or `select 1` do not), or after Laravel's `SQL: `, so that is put before it here. A
     * statement the masker cannot read is handed on as it is, and the scrubber then drops it whole.
     */
    private static function statement(string $sql): string
    {
        $sql = self::cut($sql);

        try {
            $masked = SqlMasker::mask('SQL: '.$sql);
        } catch (RuntimeException) {
            return $sql;
        }

        return str_starts_with($masked, 'SQL: ') ? substr($masked, 5) : $masked;
    }

    /** A message cut to MESSAGE_BYTES, on a character boundary. */
    private static function cut(string $text): string
    {
        return strlen($text) > self::MESSAGE_BYTES ? mb_strcut($text, 0, self::MESSAGE_BYTES, 'UTF-8') : $text;
    }
}
