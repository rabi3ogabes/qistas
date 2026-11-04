<?php

declare(strict_types=1);

namespace App\Errors;

use App\Http\ApiException;
use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PDOException;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * What breaks on the server becomes an error event here (design section 3), from three hooks: the exception handler's
 * report callback (bootstrap/app.php), the error pages a visitor is shown (RecordErrorPages) and slow queries (the
 * QueryExecuted listener of ErrorServiceProvider). It decides WHAT is recorded; ErrorRecorder decides how.
 *
 * What is recorded:
 *   - an exception the framework reports: source `database` for a failed query, a PDO error, a lost connection or a
 *     deadlock (on any request, command or job, and also when it is wrapped in another exception), else `api` on a
 *     request under /api, else `site`. Its message is never the database's message (that holds the values): it is the
 *     SQLSTATE, what kind of failure it was, and the statement with its placeholders. An ApiException below 500 is an
 *     expected answer and is not an error.
 *   - an error page: `site/http` for 403, 404, 419, 429, 500 and 503, `api/http` for any 5xx; a 404, 403 or 405 for a
 *     path only a scanner asks for is counted as scanner noise instead; a 5xx whose exception was recorded already is
 *     not a second issue; a 5xx while the site is down for maintenance is not recorded at all.
 *   - a query at or above qistas.errors.slow_query_ms (3000 when that is under 1 ms), never one on the error center's
 *     own tables or one made while the capture is at work.
 *
 * Rules that hold whatever happens:
 *   - Nothing heavy runs before the answer. A hook only notes plain values (an ErrorEvent: strings and numbers, never
 *     the request, the response or the exception) and returns; scrubbing, fingerprinting and every write run after the
 *     answer, in flush(), which defer(...)->always() calls once the response is out or the job or command has finished
 *     (and the application's terminating callback calls for a command that threw, whose failure is reported after
 *     Laravel ran its deferred work). So nothing runs inside the caller's database transaction either.
 *   - Every Throwable is swallowed, and never reported: a report would come straight back here. While flush() works,
 *     capturing() is true and every hook stands back, so a failure inside the error center can never loop.
 *   - A fingerprint is recorded at most once per request (spec 8), or once per command or job when there is no request.
 *     At most PENDING_MAX events wait for one answer; any more are counted as dropped, so a runaway loop cannot fill the
 *     memory and is still visible.
 *   - QISTAS_ERRORS=false: nothing is noted and nothing is deferred.
 *
 * Bound as a scoped instance (ErrorServiceProvider): one per request, and one per job in a queue worker.
 */
final class ErrorCapture
{
    /** The request attribute that says an exception of this request was recorded, so its 5xx page is not a second issue. */
    public const RECORDED = 'errors.recorded';

    /** The route of a 5xx on a path no route serves: one issue for all of them, never one per path (a flood of alerts). */
    public const UNMATCHED = '/{unmatched}';

    /** The error pages of the website that are recorded (spec 3). On the API it is every 5xx. */
    public const SITE_PAGES = [403, 404, 419, 429, 500, 503];

    /** The answers that, for a path only a scanner asks for, are scanner noise. */
    public const SCANNER_STATUSES = [403, 404, 405];

    /** Events that may wait for one answer (or one command or job); any more are counted as dropped. */
    public const PENDING_MAX = 100;

    /** The threshold when qistas.errors.slow_query_ms is under 1 ms: an empty or mistyped setting is 0 (ruling R9). */
    public const SLOW_QUERY_MS = 3000.0;

    /** The request attribute that holds the fingerprints recorded for that request. */
    private const SEEN = 'errors.seen';

    /** Fingerprints remembered outside a request before the list starts again (a long command). */
    private const SEEN_MAX = 1000;

    /** Frames of a trace that are kept (the scrubber keeps 8 KB of them, so far fewer reach the table). */
    private const FRAMES = 100;

    /** Bytes of an exception's message that are kept before scrubbing (the scrubber reads far less). */
    private const MESSAGE_BYTES = 16384;

    /** Exceptions looked through, the given one and those it wraps, for a database failure. */
    private const CHAIN = 8;

    /** The name of the deferred flush: Laravel keeps one of each name. */
    private const DEFERRED = 'errors.capture';

    /** The error center's own tables (ruling M10): a slow query on one of them is never recorded. */
    private const OWN_TABLES = '~\berror_(?:issues|occurrences|issue_days|issue_people|noise_days)\b~i';

    private static bool $capturing = false;

    /** @var list<Closure(): mixed> what waits for the answer */
    private array $pending = [];

    /** @var array<string, true> the events waiting, by their fields: an exact repeat is not kept twice */
    private array $pendingKeys = [];

    /** Events that did not fit in PENDING_MAX. */
    private int $overflow = 0;

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
        if (self::$capturing || ! ErrorRecorder::enabled()) {
            return;
        }

        try {
            app(self::class)->later($work);
        } catch (Throwable) {
            // nothing to record it with: it is lost, never reported
        }
    }

    /**
     * The exception handler reported $e. $request is the request being answered (looked up when not given); none in a
     * command or a job.
     */
    public function exception(Throwable $e, ?Request $request = null): void
    {
        if (self::$capturing || ErrorRecorder::busy() || ! ErrorRecorder::enabled()) {
            return;
        }

        try {
            // A refusal the API answers in its usual shape (401, 403, 409, 410, 422) is an answer, not an error.
            if ($e instanceof ApiException && $e->status < 500) {
                return;
            }

            $request ??= self::currentRequest();
            $request?->attributes->set(self::RECORDED, true);

            $this->pend(self::exceptionEvent($e, $request));
        } catch (Throwable) {
            // never break the report it was called from
        }
    }

    /** A visitor was answered with $status (RecordErrorPages calls this once the answer is out, for 403 and above). */
    public function errorPage(Request $request, int $status): void
    {
        if (self::$capturing || ! ErrorRecorder::enabled()) {
            return;
        }

        try {
            if (in_array($status, self::SCANNER_STATUSES, true) && ErrorFingerprint::isScannerPath($request->getPathInfo())) {
                $this->later(static fn () => app(ErrorRecorder::class)->noise('scanner'));

                return;
            }

            $api = self::underApi($request);

            if ($api ? $status < 500 || $status > 599 : ! in_array($status, self::SITE_PAGES, true)) {
                return;
            }

            // The exception behind it is the issue already: one failure, one issue.
            if ($status >= 500 && $request->attributes->get(self::RECORDED) === true) {
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

    /** A query finished (the provider's listener calls this only for one of a millisecond or more). */
    public function slowQuery(QueryExecuted $query): void
    {
        if (self::$capturing || ErrorRecorder::busy() || ! ErrorRecorder::enabled()) {
            return;
        }

        try {
            if ((float) $query->time < self::slowQueryMs() || preg_match(self::OWN_TABLES, $query->sql) === 1) {
                return;
            }

            $request = self::currentRequest();

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
     * recorded already in this request (or command, or job). Runs after the answer (defer, or the terminating callback);
     * never throws.
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

            if ($this->overflow > 0) {
                $dropped = $this->overflow;
                $this->overflow = 0;

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

    /** Notes $work for after the answer; past PENDING_MAX it is only counted. */
    private function later(Closure $work): void
    {
        if (count($this->pending) >= self::PENDING_MAX) {
            $this->overflow++;

            return;
        }

        $this->pending[] = $work;

        // `always`: Laravel skips deferred work after an answer of 400 or more, which is when there is most to record.
        defer(fn () => $this->flush(), self::DEFERRED)->always();
    }

    /** Notes an event for after the answer, unless the very same one is waiting already. */
    private function pend(ErrorEvent $event): void
    {
        $key = sha1(implode("\0", [
            $event->source, $event->kind, $event->class, $event->message, $event->trace,
            (string) $event->status, (string) $event->method, (string) $event->route, (string) $event->userId,
        ]));

        if (isset($this->pendingKeys[$key])) {
            return;
        }

        $this->pendingKeys[$key] = true;
        $this->later(fn () => $this->store($event));
    }

    /** After the answer: scrubbed, fingerprinted, and recorded when its fingerprint is new in this request. */
    private function store(ErrorEvent $event): void
    {
        $scrubbed = ErrorScrubber::event($event);

        if ($this->firstTime(ErrorFingerprint::of($scrubbed))) {
            app(ErrorRecorder::class)->record($scrubbed);
        }
    }

    /**
     * Is this the first time $fingerprint is recorded in this request? The fingerprints are kept on the request (so the
     * next request, even in the same process, starts with none); with no request, on this instance, which a queue worker
     * makes anew for every job.
     */
    private function firstTime(string $fingerprint): bool
    {
        $request = self::currentRequest();

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

    // ---- what an event holds -------------------------------------------------------------------------------------

    private static function exceptionEvent(Throwable $e, ?Request $request): ErrorEvent
    {
        $database = self::databaseFailure($e);
        $subject = $database ?? $e;
        $status = $e instanceof ApiException ? $e->status : 500;

        return new ErrorEvent(
            source: $database !== null ? 'database' : ($request !== null && self::underApi($request) ? 'api' : 'site'),
            kind: 'exception',
            class: $subject::class,
            message: $database !== null ? self::databaseMessage($database) : self::cut($e->getMessage()),
            trace: self::trace($subject),
            status: $request === null ? null : $status,
            method: $request?->getMethod(),
            route: $request === null ? null : self::route($request, $status),
            userId: $request === null ? null : self::userId(),
        );
    }

    /**
     * The database failure in $e or in what it wraps (a view that failed on a query wraps the QueryException): a failed
     * query or a PDO error first, then anything whose message is the database's (it carries a SQLSTATE, or PDO says the
     * connection was lost or a lock was refused). "Connection reset by peer" from another server is not one.
     */
    private static function databaseFailure(Throwable $e): ?Throwable
    {
        $chain = [];

        for ($link = $e; $link !== null && count($chain) < self::CHAIN; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        foreach ($chain as $link) {
            if ($link instanceof PDOException || $link instanceof LostConnectionException) {
                return $link; // a QueryException is a PDOException
            }
        }

        foreach ($chain as $link) {
            $message = $link->getMessage();

            if (str_contains($message, 'SQLSTATE[')
                || (str_starts_with($message, 'PDO') && (self::lostConnection($link) || self::deadlock($link)))) {
                return $link;
            }
        }

        return null;
    }

    /**
     * What a database failure says, built from what it is and never from its message: the SQLSTATE, a lost connection or
     * a deadlock, and for a failed query the connection's name and the statement with its placeholders, in the shape
     * Laravel writes (`... (Connection: pgsql, SQL: insert into ...)`) so the scrubber reads it as SQL.
     */
    private static function databaseMessage(Throwable $failure): string
    {
        $parts = [];
        $state = self::sqlState($failure);

        if ($state !== null) {
            $parts[] = 'SQLSTATE['.$state.']';
        }

        if (self::lostConnection($failure)) {
            $parts[] = 'Lost connection to the database';
        } elseif (self::deadlock($failure)) {
            $parts[] = 'Deadlock or lock timeout';
        }

        if ($failure instanceof QueryException) {
            $parts[] = '(Connection: '.$failure->getConnectionName().', SQL: '.$failure->getSql().')';
        }

        return $parts === [] ? 'Database failure' : implode(' ', $parts);
    }

    /** The five characters of the SQLSTATE: from the driver's error info or code, or read out of the message. */
    private static function sqlState(Throwable $failure): ?string
    {
        $info = $failure instanceof PDOException ? $failure->errorInfo : null;

        foreach ([is_array($info) ? ($info[0] ?? null) : null, $failure->getCode()] as $code) {
            if (is_string($code) && preg_match('~^[0-9A-Z]{5}$~D', $code) === 1) {
                return $code;
            }
        }

        return preg_match('~SQLSTATE\[([0-9A-Z]{5})\]~', $failure->getMessage(), $found) === 1 ? $found[1] : null;
    }

    private static function lostConnection(Throwable $e): bool
    {
        return $e instanceof LostConnectionException || (new LostConnectionDetector)->causedByLostConnection($e);
    }

    private static function deadlock(Throwable $e): bool
    {
        return (new ConcurrencyErrorDetector)->causedByConcurrencyError($e);
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

    private static function slowQueryMs(): float
    {
        $ms = config('qistas.errors.slow_query_ms');

        return is_numeric($ms) && (float) $ms >= 1 ? (float) $ms : self::SLOW_QUERY_MS;
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
