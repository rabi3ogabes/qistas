<?php

declare(strict_types=1);

namespace App\Errors;

use App\Errors\Events\ErrorIssueCreated;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The one way into the error center's tables (design sections 4, 5 and 8). For every event it:
 *
 *   1. scrubs it (ErrorScrubber::event, again even when the caller already did: it is idempotent, and nothing here trusts
 *      its input) and fingerprints the scrubbed event;
 *   2. in one transaction, under a lock on the issue's row, counts a sighting of a known issue: the count, the newest five
 *      samples, the day, the person (while under 500), the versions; or reopens a resolved one; or drops it silently when
 *      the issue is ignored;
 *   3. for a fingerprint it has not seen, checks the flood caps and opens a new issue in a transaction of its own, then
 *      fires ErrorIssueCreated once that transaction has committed;
 *   4. when the database refuses any of it, appends the scrubbed event to the spool instead (ErrorSpool), so the import
 *      command can record it once the database answers.
 *
 * Rules that hold whatever happens:
 *   - record() never throws, so a hook that calls it can never break a request. It never reports or logs what it was
 *     given, and it never calls the capture layer: a failure inside it is never recorded as an error.
 *   - While it is at work (a listener of ErrorIssueCreated, a query listener reacting to its own queries) a nested call
 *     writes nothing and returns Dropped: a failure of the error center itself cannot loop back into it.
 *   - Every statement it sends runs inside one of its own transactions, so a refused statement never leaves a caller's
 *     PostgreSQL transaction in a failed state (inside a caller's transaction, Laravel's nesting is a savepoint).
 *   - QISTAS_ERRORS=false: nothing is written anywhere, not even the spool.
 *
 * Flood caps (spec 8), counted in the cache with keys that carry the minute or the hour: at most 60 new issues a minute
 * and 500 an hour from the server's own sources; the client-reported kinds (a browser's script error, a phone's crash),
 * which any stranger can send, have a budget of their own of 20 a minute, so a flood of them can never use up the budget
 * that a real server error needs. A sighting of a known issue is never capped. A counter that cannot be read or written
 * keeps the gate shut (fails closed): a failing cache can never let a flood through. Everything over a cap is counted as
 * `dropped` in error_noise_days, so nothing is invisible.
 */
final class ErrorRecorder
{
    /** Sightings kept per issue; writing a sixth deletes the oldest. */
    public const SAMPLES = 5;

    /** Different people counted per issue; after that `people` stops growing. */
    public const PEOPLE = 500;

    public const NEW_PER_MINUTE = 60;

    public const NEW_PER_HOUR = 500;

    /** New issues a minute from the kinds that browsers and phones report (CLIENT_KINDS). */
    public const CLIENT_NEW_PER_MINUTE = 20;

    /** The kinds an untrusted client reports: never verified, never an instant e-mail (spec 9). */
    public const CLIENT_KINDS = ['script', 'crash'];

    /** What error_noise_days counts: requests only a scanner makes, and everything over a flood cap. */
    public const NOISE_KINDS = ['scanner', 'dropped'];

    /** How many times a new fingerprint is tried when another request opens the same issue at the same instant. */
    private const ATTEMPTS = 2;

    private static bool $busy = false;

    public function __construct(private readonly ErrorSpool $spool) {}

    /** Is capture switched on (QISTAS_ERRORS, on by default)? */
    public static function enabled(): bool
    {
        return filter_var(config('qistas.errors.enabled'), FILTER_VALIDATE_BOOL);
    }

    /** Is the recorder at work right now? The capture layer's listeners stand back while it is. */
    public static function busy(): bool
    {
        return self::$busy;
    }

    /** A browser's script error or a phone's crash: reported by a client, so untrusted. */
    public static function clientReported(string $source, string $kind): bool
    {
        return $source === 'app' || in_array($kind, self::CLIENT_KINDS, true);
    }

    /**
     * The 16 hex characters that stand for a person: the start of an HMAC-SHA256 of the user id with the application key,
     * enough to count different people and never enough to name one. The spool writes it as `h:` and the 16 characters
     * (see spooledPerson), and that form is recognised here, so an imported event counts the same person as a direct one.
     */
    public static function person(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        if (preg_match('~^h:([0-9a-f]{16})$~D', $userId, $found) === 1) {
            return $found[1];
        }

        $key = config('app.key');

        return substr(hash_hmac('sha256', $userId, is_string($key) ? $key : ''), 0, 16);
    }

    /** How the spool writes a person: `h:` and the person hash, never the user id. */
    public static function spooledPerson(?string $userId): ?string
    {
        $person = self::person($userId);

        return $person === null ? null : 'h:'.$person;
    }

    /**
     * Records one event and says what became of it. Never throws: when the database refuses the write, the scrubbed event
     * goes to the spool (Spooled), and when the spool cannot take it either, it is lost (Dropped).
     */
    public function record(ErrorEvent $event): Recorded
    {
        if (! self::enabled()) {
            return Recorded::Off;
        }

        if (self::$busy) {
            return Recorded::Dropped;
        }

        self::$busy = true;

        try {
            try {
                $scrubbed = ErrorScrubber::event($event);
            } catch (Throwable) {
                return Recorded::Dropped; // what could not be scrubbed is never kept, not even in the spool
            }

            try {
                return $this->write($scrubbed);
            } catch (Throwable) {
                return $this->spool->append($scrubbed) ? Recorded::Spooled : Recorded::Dropped;
            }
        } catch (Throwable) {
            return Recorded::Dropped;
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Records an event read back from the spool: the same as record(), except that a database that still refuses it
     * throws, so the spool keeps the line instead of writing it a second time.
     *
     * @throws Throwable when the database refuses the write
     */
    public function replay(ErrorEvent $event): Recorded
    {
        if (! self::enabled()) {
            return Recorded::Off;
        }

        if (self::$busy) {
            return Recorded::Dropped;
        }

        self::$busy = true;

        try {
            return $this->write(ErrorScrubber::event($event));
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Adds to error_noise_days: `scanner` (a request only a scanner makes) or `dropped` (over a flood cap, or lost on the
     * way), on the given day of the application clock (today unless told). Any other kind is ignored. A counter that
     * cannot be written is lost; it never throws.
     */
    public function noise(string $kind, ?CarbonInterface $day = null, int $count = 1): void
    {
        if (! self::enabled() || $count < 1 || ! in_array($kind, self::NOISE_KINDS, true)) {
            return;
        }

        try {
            $date = CarbonImmutable::instance($day ?? now())->setTimezone(self::timezone())->toDateString();
            $db = self::connection();

            $db->transaction(function () use ($db, $date, $kind, $count): void {
                $db->table('error_noise_days')->insertOrIgnore(['day' => $date, 'kind' => $kind, 'count' => 0]);
                $db->table('error_noise_days')->where('day', $date)->where('kind', $kind)->increment('count', $count);
            });
        } catch (Throwable) {
            // a lost count is not an error of its own
        }
    }

    /**
     * Stores one scrubbed event. Throws whatever the database throws.
     */
    private function write(ErrorEvent $event): Recorded
    {
        $fingerprint = ErrorFingerprint::of($event);
        $at = self::moment($event->at);
        $person = self::person($event->userId);
        $db = self::connection();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $seen = $db->transaction(fn (): ?Recorded => $this->sighting($db, $fingerprint, $event, $at, $person));

            if ($seen !== null) {
                return $seen;
            }

            if (! $this->mayOpen($event)) {
                $this->noise('dropped');

                return Recorded::Dropped;
            }

            try {
                $issue = $db->transaction(fn (): ErrorIssue => $this->open($db, $fingerprint, $event, $at, $person));
            } catch (UniqueConstraintViolationException) {
                // Another request opened the same issue a moment ago. Its insert won; this sighting is counted on that
                // issue in the next attempt. The refused insert was rolled back with its transaction (a savepoint when
                // nested), so no PostgreSQL transaction is left failed.
                continue;
            }

            $this->announce($issue);

            return Recorded::Created;
        }

        throw new RuntimeException('An error issue could be neither found nor opened.');
    }

    /** A sighting of a known issue, under a lock on its row; null when the fingerprint is new. */
    private function sighting(Connection $db, string $fingerprint, ErrorEvent $event, CarbonImmutable $at, ?string $person): ?Recorded
    {
        $issue = ErrorIssue::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

        if ($issue === null) {
            return null;
        }

        if ($issue->status === ErrorIssue::STATUS_IGNORED) {
            return Recorded::Ignored; // not counted, not sampled, and its last sighting is not renewed
        }

        // A sighting from before the issue was resolved (read back from the spool after someone resolved it) is counted,
        // but it is not the issue coming back.
        $reopens = $issue->status === ErrorIssue::STATUS_RESOLVED
            && ($issue->resolved_at === null || $at->greaterThanOrEqualTo($issue->resolved_at));

        $earlier = $at->lessThan($issue->first_seen_at);
        $counted = $person !== null && $issue->people < self::PEOPLE && $this->addPerson($db, $issue->id, $person);

        $issue->forceFill([
            'occurrences' => $issue->occurrences + 1,
            'people' => $issue->people + ($counted ? 1 : 0),
            'first_seen_at' => $earlier ? $at : $issue->first_seen_at,
            'last_seen_at' => $at->greaterThan($issue->last_seen_at) ? $at : $issue->last_seen_at,
            // the version of the first sighting (an earlier one read back from the spool moves it back) ...
            'first_version' => $event->version !== null && ($earlier || $issue->first_version === null) ? $event->version : $issue->first_version,
            // ... and the highest version seen; a sighting without a version changes neither
            'last_version' => self::higher($issue->last_version, $event->version),
        ]);

        if ($reopens) {
            $issue->forceFill([
                'status' => ErrorIssue::STATUS_OPEN,
                'reopened_at' => $at,
                'reopened_version' => $event->version,
            ]);
        }

        $issue->save();

        $this->sample($issue->id, $event, $at);
        $this->trimSamples($issue->id);
        $this->countDay($db, $issue->id, $at);

        return $reopens ? Recorded::Reopened : Recorded::Counted;
    }

    /** A new issue with its first sample, day and person. The unique fingerprint refuses a second one. */
    private function open(Connection $db, string $fingerprint, ErrorEvent $event, CarbonImmutable $at, ?string $person): ErrorIssue
    {
        /** @var ErrorIssue $issue */
        $issue = ErrorIssue::query()->forceCreate([
            'fingerprint' => $fingerprint,
            'source' => $event->source,
            'kind' => $event->kind,
            'title' => ErrorFingerprint::title($event),
            'class' => self::className($event),
            'status' => ErrorIssue::STATUS_OPEN,
            'occurrences' => 1,
            'people' => $person === null ? 0 : 1,
            'first_seen_at' => $at,
            'last_seen_at' => $at,
            'first_version' => $event->version,
            'last_version' => $event->version,
        ]);

        $this->sample($issue->id, $event, $at);
        $db->table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => $at->toDateString(), 'count' => 1]);

        if ($person !== null) {
            $db->table('error_issue_people')->insert(['issue_id' => $issue->id, 'person' => $person]);
        }

        return $issue;
    }

    private function sample(string $issueId, ErrorEvent $event, CarbonImmutable $at): void
    {
        ErrorOccurrence::query()->create([
            'issue_id' => $issueId,
            'occurred_at' => $at,
            'status' => $event->status,
            'method' => $event->method,
            'route' => $event->route,
            'message' => $event->message,
            'trace' => $event->trace,
            'version' => $event->version,
            'platform' => $event->platform,
            'device' => $event->device,
        ]);
    }

    /** Keeps the newest five: by time, then by id, since two sightings can share a second. */
    private function trimSamples(string $issueId): void
    {
        $keep = ErrorOccurrence::query()->where('issue_id', $issueId)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(self::SAMPLES)->pluck('id')->all();

        ErrorOccurrence::query()->where('issue_id', $issueId)->whereNotIn('id', $keep)->delete();
    }

    private function countDay(Connection $db, string $issueId, CarbonImmutable $at): void
    {
        $day = $at->toDateString();

        $db->table('error_issue_days')->insertOrIgnore(['issue_id' => $issueId, 'day' => $day, 'count' => 0]);
        $db->table('error_issue_days')->where('issue_id', $issueId)->where('day', $day)->increment('count');
    }

    /** Adds the person to the issue; false when they were counted already. */
    private function addPerson(Connection $db, string $issueId, string $person): bool
    {
        return $db->table('error_issue_people')->insertOrIgnore(['issue_id' => $issueId, 'person' => $person]) > 0;
    }

    /** May a new issue be opened now? Checked outside every transaction, so a cache on the database never sits in one. */
    private function mayOpen(ErrorEvent $event): bool
    {
        $now = now()->utc();

        if (self::clientReported($event->source, $event->kind)) {
            return $this->withinBudget('client:'.$now->format('YmdHi'), self::CLIENT_NEW_PER_MINUTE, 120);
        }

        return $this->withinBudget('minute:'.$now->format('YmdHi'), self::NEW_PER_MINUTE, 120)
            && $this->withinBudget('hour:'.$now->format('YmdH'), self::NEW_PER_HOUR, 7200);
    }

    /** One more against the counter of this window; false when it is over the limit or cannot be counted. */
    private function withinBudget(string $window, int $limit, int $seconds): bool
    {
        $key = 'errors:new-issues:'.$window;

        try {
            Cache::add($key, 0, $seconds);
            $count = Cache::increment($key);
        } catch (Throwable) {
            return false; // a counter that cannot be read is a full one: a failing cache never opens the gate
        }

        return is_int($count) && $count >= 1 && $count <= $limit;
    }

    /** Fires ErrorIssueCreated once the transaction that opened the issue has committed; a listener's failure stays its own. */
    private function announce(ErrorIssue $issue): void
    {
        try {
            $issue->getConnection()->afterCommit(static function () use ($issue): void {
                try {
                    event(new ErrorIssueCreated($issue));
                } catch (Throwable) {
                    // a listener that fails (a mail that would not go) changes nothing here: the issue stays stored
                }
            });
        } catch (Throwable) {
            // no transaction manager: there is nobody to tell
        }
    }

    /** For an error page the class is `http.<status>` (as in the fingerprint); otherwise the event's class, if any. */
    private static function className(ErrorEvent $event): ?string
    {
        if ($event->kind === 'http') {
            return $event->status === null ? null : 'http.'.$event->status;
        }

        return $event->class === '' ? null : $event->class;
    }

    /** The higher of two versions (version_compare: 1.10.0 is above 1.9.0); a missing one never replaces a known one. */
    private static function higher(?string $known, ?string $seen): ?string
    {
        if ($seen === null) {
            return $known;
        }

        if ($known === null) {
            return $seen;
        }

        return version_compare($seen, $known, '>') ? $seen : $known;
    }

    /** When it happened, on the application clock; a time in the future (a clock that is ahead) is now. */
    private static function moment(string $at): CarbonImmutable
    {
        $now = now()->toImmutable();

        try {
            $moment = CarbonImmutable::parse($at);
        } catch (Throwable) {
            $moment = $now;
        }

        return ($moment->greaterThan($now) ? $now : $moment)->setTimezone(self::timezone());
    }

    private static function timezone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    private static function connection(): Connection
    {
        return (new ErrorIssue)->getConnection();
    }
}
