<?php

declare(strict_types=1);

namespace App\Errors;

use App\Errors\Events\ErrorIssueCreated;
use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Cache\DatabaseStore;
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
 *   4. when the database refuses any of it, appends the scrubbed event (with its fingerprint) to the spool instead
 *      (ErrorSpool), so the import command can record it once the database answers.
 *
 * Rules that hold whatever happens:
 *   - record() never throws, so a hook that calls it can never break a request. It never reports or logs what it was
 *     given, and it never calls the capture layer: a failure inside it is never recorded as an error.
 *   - While it is at work (a listener of ErrorIssueCreated, a query listener reacting to its own queries) a nested call
 *     writes nothing: record() returns Dropped, replay() throws RecorderBusy. A failure of the error center itself cannot
 *     loop back into it.
 *   - Every statement it sends to record an event runs inside one of its own transactions (transaction()), so a refused
 *     statement never leaves a caller's PostgreSQL transaction in a failed state (inside a caller's transaction, Laravel's
 *     nesting is a savepoint), and on PostgreSQL no lock is waited for longer than a second. Two things are not: noise()
 *     is one upsert with no transaction, no savepoint and no lock timeout (it holds its day's row only for the moment of
 *     that statement; the capture layer calls it after the answer, outside any caller's transaction), and the flood-cap
 *     counters get a transaction only when the cache keeps them in this database (counting()).
 *   - The counters are added to in the database (`occurrences = occurrences + 1`), never written back as a number read
 *     before.
 *   - QISTAS_ERRORS=false: nothing is written anywhere, not even the spool.
 *
 * Flood caps (spec 8, rulings R15 and R18): new issues draw on one of four budgets (BUDGETS), each with counters of its own
 * in the cache, keyed by the minute and the hour. A failure that can send the instant e-mail never shares a budget with what
 * any visitor can make at will (a new 404 for a made-up path, a browser's script error, a phone's crash) or with slow
 * queries, so no flood of those can stop a real server error being opened; and browsers' script errors and phones' crashes
 * have a budget each. Inside the client budgets one sender (an address, or the person on a phone's app token) may open only
 * SENDER_NEW_ISSUES new issues an hour, so one sender cannot use a whole budget up. A sighting of a known issue is never capped. A counter
 * that cannot be read or written keeps the gate shut (fails closed): a failing cache can never let a flood through.
 * Everything over a cap is counted as `dropped` in error_noise_days, so nothing is invisible.
 */
final class ErrorRecorder
{
    /** Sightings kept per issue; writing a sixth deletes the oldest. */
    public const SAMPLES = 5;

    /** Different people counted per issue; after that `people` stops growing. */
    public const PEOPLE = 500;

    /**
     * How many new issues each budget may open in a minute and in an hour (see budget() for what draws on which).
     */
    public const BUDGETS = [
        // Failures that can send the instant e-mail: an exception, or an error page of 500 or more, from site, api or database.
        'alert' => ['minute' => 60, 'hour' => 500],
        // The server's failures that never alert at once: error pages below 500 (any visitor can make a new 404) and slow queries.
        'quiet' => ['minute' => 30, 'hour' => 200],
        // What browsers report (script) and what phones report (crash): unverifiable, and anyone can send either. Each has a
        // budget of its own, so a flood of one never uses up the other's.
        'script' => ['minute' => 20, 'hour' => 100],
        'crash' => ['minute' => 20, 'hour' => 100],
    ];

    /**
     * How many new client issues one sender (a browser's address, the person on a phone's token, or a phone's address when it
     * has no token) may open in an hour. A budget is shared by everyone; this keeps one sender from using all of it (ruling
     * R18). By person, not by token: every sign-in makes a new token (ClientSender::quotaKey).
     */
    public const SENDER_NEW_ISSUES = 3;

    /** The kinds an untrusted client reports: never verified, never an instant e-mail (spec 9). */
    public const CLIENT_KINDS = ['script', 'crash'];

    /** What error_noise_days counts: requests only a scanner makes, and everything over a flood cap. */
    public const NOISE_KINDS = ['scanner', 'dropped'];

    /** On PostgreSQL, the longest the recorder waits for a lock before its write is refused (and the event spooled). */
    public const LOCK_TIMEOUT = '1s';

    /** How many times a new fingerprint is tried when another request opens the same issue at the same instant. */
    private const ATTEMPTS = 2;

    /** How long occurrences are kept when qistas.errors.occurrence_days is not a usable number. */
    private const OCCURRENCE_DAYS = 30;

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
     * The budget a new issue draws on (BUDGETS): `script` for what a browser reports and `crash` for what a phone reports
     * (whatever a phone sends is a phone's), `alert` for what can send the instant e-mail (an exception, or an error page of
     * 500 or more), `quiet` for the rest (error pages below 500, slow queries).
     */
    public static function budget(string $source, string $kind, ?int $status): string
    {
        if (self::clientReported($source, $kind)) {
            return $source === 'app' || $kind === 'crash' ? 'crash' : 'script';
        }

        return $kind === 'exception' || ($kind === 'http' && $status !== null && $status >= 500) ? 'alert' : 'quiet';
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
     * Runs $work in a transaction of the recorder's own. On PostgreSQL, when it is the outermost transaction, a lock is
     * waited for at most LOCK_TIMEOUT (`SET LOCAL` lasts until that transaction ends; inside a caller's transaction it is
     * not set, since it would change the caller's), so a flood of one failure cannot line the PHP workers up behind one
     * row. A wait that runs out is refused like any other statement: the transaction is rolled back and the event goes to
     * the spool.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function transaction(Connection $db, Closure $work): mixed
    {
        return $db->transaction(static function () use ($db, $work): mixed {
            if ($db->getDriverName() === 'pgsql' && $db->transactionLevel() === 1) {
                $db->unprepared("set local lock_timeout = '".self::LOCK_TIMEOUT."'");
            }

            return $work();
        });
    }

    /**
     * Records one event and says what became of it. Never throws: when the database refuses the write, the scrubbed event
     * goes to the spool (Spooled), and when the spool cannot take it either, it is lost (Dropped).
     *
     * $sender is for what a client reports (a browser's script error, a phone's crash): an opaque key for whoever sent it,
     * which the controller builds from the address or the person on the app token (ClientSender::quotaKey). It is used for
     * one thing, SENDER_NEW_ISSUES: a sender that has opened that many new issues this hour gets no more (Dropped, counted),
     * and takes nothing from the budget of everyone else. It is turned into a keyed hash for the cache key and never
     * stored, written to the spool or logged. A sighting of an issue that exists is never held to it.
     */
    public function record(ErrorEvent $event, ?string $sender = null): Recorded
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
                $fingerprint = ErrorFingerprint::of($scrubbed);
            } catch (Throwable) {
                return Recorded::Dropped; // what could not be scrubbed is never kept, not even in the spool
            }

            try {
                $recorded = $this->write($scrubbed, $fingerprint, $sender);
            } catch (Throwable) {
                return $this->spool->append($scrubbed, $fingerprint) ? Recorded::Spooled : Recorded::Dropped;
            }

            $this->countLost();

            return $recorded;
        } catch (Throwable) {
            return Recorded::Dropped;
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Records an event read back from the spool: the same as record(), except that a database that still refuses it
     * throws, so the spool keeps the line instead of writing it a second time. The fingerprint the line was written with is
     * used only when it can be believed (trustedFingerprint): so the scrubber's rare second opinion on a text never opens a
     * second issue for the same failure, and a line edited by hand can never choose the issue it is counted on.
     *
     * @throws RecorderBusy when the recorder is already at work: the line was not recorded and must be kept
     * @throws Throwable when the database refuses the write
     */
    public function replay(ErrorEvent $event, ?string $fingerprint = null): Recorded
    {
        if (! self::enabled()) {
            return Recorded::Off;
        }

        if (self::$busy) {
            throw new RecorderBusy('The error recorder is already at work.');
        }

        self::$busy = true;

        try {
            $scrubbed = ErrorScrubber::event($event);

            return $this->write($scrubbed, $this->trustedFingerprint($scrubbed, $fingerprint), null);
        } finally {
            self::$busy = false;
        }
    }

    /**
     * The fingerprint a spool line is recorded under. The one it was written with (40 hex characters) when an issue with it
     * exists and is of the same source and kind as the line: the same failure, whatever a second scrubbing of its text says.
     * Otherwise the one worked out from the line now: a fingerprint no issue has yet is never taken on the line's word (it
     * would be the same as the one worked out, or a choice the line made), nor one of an issue of another source or kind (a
     * browser's script error counted on an API failure, which can send the instant e-mail). Throws what the database throws.
     */
    private function trustedFingerprint(ErrorEvent $scrubbed, ?string $stored): string
    {
        $computed = ErrorFingerprint::of($scrubbed);

        if ($stored === null || $stored === $computed || preg_match('~^[0-9a-f]{40}$~D', $stored) !== 1) {
            return $computed;
        }

        $db = self::connection();
        $issue = self::transaction($db, static fn (): ?ErrorIssue => ErrorIssue::query()->where('fingerprint', $stored)->first(['source', 'kind']));

        return $issue !== null && $issue->source === $scrubbed->source && $issue->kind === $scrubbed->kind ? $stored : $computed;
    }

    /**
     * Adds to error_noise_days: `scanner` (a request only a scanner makes) or `dropped` (over a flood cap, or lost on the
     * way), on the given day of the application clock (today unless told). Any other kind is ignored. A counter that
     * cannot be written is lost; it never throws.
     *
     * One statement and no transaction (`insert ... on conflict (day, kind) do update set count = count + excluded.count`,
     * on SQLite and PostgreSQL alike): a flood of scanners or of a repeated error page adds to one row per day, and each
     * add holds that row only for the moment of its own statement.
     */
    public function noise(string $kind, ?CarbonInterface $day = null, int $count = 1): void
    {
        $this->countNoise($kind, $day, $count);
    }

    /** noise(), saying whether the count was written. */
    private function countNoise(string $kind, ?CarbonInterface $day, int $count): bool
    {
        if (! self::enabled() || $count < 1 || ! in_array($kind, self::NOISE_KINDS, true)) {
            return false;
        }

        try {
            $date = CarbonImmutable::instance($day ?? now())->setTimezone(self::timezone())->toDateString();
            $db = self::connection();
            $grammar = $db->getQueryGrammar();

            $db->table('error_noise_days')->upsert(
                [['day' => $date, 'kind' => $kind, 'count' => $count]],
                ['day', 'kind'],
                ['count' => $db->raw($grammar->wrapTable('error_noise_days').'.'.$grammar->wrap('count').' + excluded.'.$grammar->wrap('count'))],
            );

            return true;
        } catch (Throwable) {
            return false; // a lost count is not an error of its own
        }
    }

    /**
     * What the spool could not take in this process is counted as dropped in error_noise_days, now that the database
     * answers (after a write that went through, and at the end of an import). Kept for later when it cannot be counted.
     */
    public function countLost(): void
    {
        $lost = ErrorSpool::lost();

        if ($lost > 0 && $this->countNoise('dropped', null, $lost)) {
            ErrorSpool::forgetLost($lost);
        }
    }

    /**
     * Stores one scrubbed event under its fingerprint. Throws whatever the database throws.
     */
    private function write(ErrorEvent $event, string $fingerprint, ?string $sender): Recorded
    {
        $at = self::moment($event->at);
        $person = self::person($event->userId);
        $db = self::connection();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $seen = self::transaction($db, fn (): ?Recorded => $this->sighting($db, $fingerprint, $event, $at, $person));

            if ($seen !== null) {
                return $seen;
            }

            $taken = $this->mayOpen($db, $event, $sender);

            if ($taken === null) {
                $this->noise('dropped');

                return Recorded::Dropped;
            }

            try {
                $issue = self::transaction($db, fn (): ErrorIssue => $this->open($db, $fingerprint, $event, $at, $person));
            } catch (UniqueConstraintViolationException) {
                // Another request opened the same issue a moment ago. Its insert won; this sighting is counted on that
                // issue in the next attempt, and the place this one took in the budget is given back. The refused insert
                // was rolled back with its transaction (a savepoint when nested), so no PostgreSQL transaction is left
                // failed.
                $this->giveBack($db, $taken);

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
        $grammar = $db->getQueryGrammar();

        $changes = [
            // the counts are added to in the database, so nothing written in between is lost
            'occurrences' => $db->raw($grammar->wrap('occurrences').' + 1'),
            'first_seen_at' => $earlier ? $at : $issue->first_seen_at,
            'last_seen_at' => $at->greaterThan($issue->last_seen_at) ? $at : $issue->last_seen_at,
            // the version of the first sighting (an earlier one read back from the spool moves it back) ...
            'first_version' => $event->version !== null && ($earlier || $issue->first_version === null) ? $event->version : $issue->first_version,
            // ... and the highest version seen; a sighting without a version changes neither
            'last_version' => self::higher($issue->last_version, $event->version),
        ];

        if ($counted) {
            $changes['people'] = $db->raw($grammar->wrap('people').' + 1');
        }

        if ($reopens) {
            $changes += [
                'status' => ErrorIssue::STATUS_OPEN,
                'reopened_at' => $at,
                'reopened_version' => $event->version,
            ];
        }

        ErrorIssue::query()->whereKey($issue->id)->update($changes);

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

    /** Keeps the newest five of this issue: by time, then by id, since two sightings can share a second. */
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

    /**
     * May a new issue be opened now? Takes a place in the minute's and the hour's counters of its budget and returns the
     * counters it took (to give back if another request opens the issue first), or null when either is full. A cache kept
     * in this database counts in a transaction of its own, so it waits for its row at most as long as any other write
     * (counting()).
     *
     * For what a client reports, and a sender given, the sender's own counter for the hour comes first: a sender over its
     * quota is turned away before it touches the budget everyone shares, and a place it took is given back when the budget
     * then turns it away (it opened nothing).
     *
     * @return list<string>|null
     */
    private function mayOpen(Connection $db, ErrorEvent $event, ?string $sender): ?array
    {
        $budget = self::budget($event->source, $event->kind, $event->status);
        $now = now()->utc();
        $taken = [];
        $mine = null;

        if ($sender !== null && self::clientReported($event->source, $event->kind)) {
            $mine = 'errors:sender:'.self::senderKey($sender).':'.$now->format('YmdH');

            if (! $this->withinBudget($db, $mine, self::SENDER_NEW_ISSUES, 7200)) {
                return null;
            }

            $taken[] = $mine;
        }

        foreach (['minute' => ['YmdHi', 120], 'hour' => ['YmdH', 7200]] as $window => [$format, $seconds]) {
            $key = "errors:new-issues:{$budget}:{$window}:".$now->format($format);

            if (! $this->withinBudget($db, $key, self::BUDGETS[$budget][$window], $seconds)) {
                if ($mine !== null) {
                    $this->giveBack($db, [$mine]); // the sender opened nothing
                }

                return null;
            }

            $taken[] = $key;
        }

        return $taken;
    }

    /** The part of a sender's cache key that stands for it: a keyed hash, so an address is never written into the cache. */
    private static function senderKey(string $sender): string
    {
        $key = config('app.key');

        return substr(hash_hmac('sha256', $sender, is_string($key) ? $key : ''), 0, 32);
    }

    /** One more against the counter $key; false when it is over the limit or cannot be counted. */
    private function withinBudget(Connection $db, string $key, int $limit, int $seconds): bool
    {
        try {
            $count = self::counting($db, static function () use ($key, $seconds): mixed {
                Cache::add($key, 0, $seconds);

                return Cache::increment($key);
            });
        } catch (Throwable) {
            return false; // a counter that cannot be read is a full one: a failing cache never opens the gate
        }

        return is_int($count) && $count >= 1 && $count <= $limit;
    }

    /**
     * Gives back the places a new issue took in its budget, when another request opened the same issue first.
     *
     * @param  list<string>  $taken
     */
    private function giveBack(Connection $db, array $taken): void
    {
        foreach ($taken as $key) {
            try {
                self::counting($db, static fn (): mixed => Cache::decrement($key));
            } catch (Throwable) {
                // a place not given back is only a place less in this minute
            }
        }
    }

    /**
     * Runs $work on the cache's counters: in a transaction of the recorder's own when the cache keeps them in this database
     * (so a cache row waits for its lock no longer than any other write, and a refusal never spoils a caller's transaction),
     * and as it is otherwise. A cache in memory, Redis or a file has nothing for a transaction to hold: on PostgreSQL that
     * would be a BEGIN, a SET and a COMMIT for nothing, twice for every new issue.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private static function counting(Connection $db, Closure $work): mixed
    {
        $store = Cache::getStore();
        $cache = $store instanceof DatabaseStore ? $store->getConnection() : null;

        return $cache instanceof Connection && $cache->getName() === $db->getName()
            ? self::transaction($db, $work)
            : $work();
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

    /**
     * When it happened, on the application clock, kept between the oldest time an occurrence is kept for
     * (qistas.errors.occurrence_days) and now: a clock that is ahead, or a damaged spool line dated in the year 1 (or a
     * year PostgreSQL cannot even store), never puts a sighting outside what the screens show and retention removes.
     */
    private static function moment(string $at): CarbonImmutable
    {
        $now = now()->toImmutable();
        $oldest = $now->subDays(self::occurrenceDays());

        try {
            $moment = CarbonImmutable::parse($at);
        } catch (Throwable) {
            $moment = $now;
        }

        $moment = match (true) {
            $moment->greaterThan($now) => $now,
            $moment->lessThan($oldest) => $oldest,
            default => $moment,
        };

        return $moment->setTimezone(self::timezone());
    }

    /** How many days sightings are kept: the setting when it is a whole number of at least 1, else 30. The detail page says it. */
    public static function occurrenceDays(): int
    {
        $days = config('qistas.errors.occurrence_days');

        return is_numeric($days) && (int) $days >= 1 ? (int) $days : self::OCCURRENCE_DAYS;
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
