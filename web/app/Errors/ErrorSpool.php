<?php

declare(strict_types=1);

namespace App\Errors;

use App\Models\ErrorIssue;
use Closure;
use Throwable;

/**
 * Where an error waits while the database is away (design section 8): one file, storage/logs/error-spool.ndjson, at most
 * 2 MB, written by ErrorRecorder when its write is refused and read back by `qistas:errors:import-spool` (every five
 * minutes) once the database answers.
 *
 * The file holds one JSON object per line: the fields of ErrorEvent::toArray() of the SCRUBBED event (append() scrubs it
 * again, so nothing that was not cleaned ever reaches the disk), except that `user_id` is never a user id: it is `h:`
 * followed by the 16 hex characters of the person hash (ErrorRecorder::spooledPerson), or null. Each line also carries
 * `fingerprint`, the 40 hex characters the direct write would have used (the import uses it again, so it counts the very
 * same issue), and a line the database has refused while its tables answered carries `tries`, the number of those
 * refusals.
 *
 * Rules:
 *   - append() never throws and never blocks for long: it takes the file's lock (LOCK_EX) only to check the size and
 *     write one line. A line that would take the file past 2 MB is not written; it is lost and counted in memory for this
 *     process (lost()), and that count is added to `dropped` in error_noise_days by the next write that goes through.
 *     A file whose last line was cut short by a crash gets its line break first, so the next line is never glued to it.
 *   - import() holds a lock of its own (the `.lock` file beside the spool) so that two imports never record a line twice,
 *     and takes the spool's lock only to read it and, at the end, to put back what it did not record, followed by
 *     whatever was appended in the meantime. It works through the lines in order and stops at the first one the database
 *     refuses (keeping it and the rest), or after a time budget, so a full spool on a slow database is worked off over
 *     several runs instead of never finishing. A line that cannot be read is skipped and counted as dropped. A line the
 *     database refuses three times while its tables answer is dropped too, so one bad line can never hold back the
 *     spool for good. A line the recorder could not take because it was at work is kept, untouched.
 *   - With QISTAS_ERRORS=false nothing is written and nothing is read.
 */
final class ErrorSpool
{
    /** The most the file may hold. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Refusals, while the error tables answer, after which a line is dropped. */
    public const MAX_TRIES = 3;

    /** How long one import works through the lines before it leaves the rest for the next run. */
    public const IMPORT_SECONDS = 20.0;

    /** Read to tell "the database is away" (keep every line) from "this line is refused" (count a try against it). */
    private const TABLES = ['error_issues', 'error_occurrences', 'error_issue_days', 'error_issue_people', 'error_noise_days'];

    /** What a replay can say that means the line is in the tables. */
    private const RECORDED = [Recorded::Created, Recorded::Counted, Recorded::Reopened];

    private static int $lost = 0;

    /** The lines the last import() took out of the spool without recording them. */
    private int $dropped = 0;

    /**
     * Where the spool is: storage/logs/error-spool.ndjson. `qistas.errors.spool_path` (not in config/qistas.php, not an
     * environment variable) lets a test point it elsewhere.
     */
    public static function path(): string
    {
        $path = config('qistas.errors.spool_path');

        return is_string($path) && $path !== '' ? $path : storage_path('logs'.DIRECTORY_SEPARATOR.'error-spool.ndjson');
    }

    /** Events this process could not spool (the file full, or not writable) and that are not counted anywhere yet. */
    public static function lost(): int
    {
        return self::$lost;
    }

    /** Takes $count off the lost events, once they are counted in error_noise_days. */
    public static function forgetLost(int $count): void
    {
        self::$lost = max(0, self::$lost - max(0, $count));
    }

    /**
     * The lines the last import() of this spool took out without recording them: unreadable ones, ones the database kept
     * refusing, ones cut to fit, and ones the recorder dropped (over a flood cap) or left out (an ignored issue).
     */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * Appends one event as a line, with the fingerprint the recorder worked out for it (worked out here when not given).
     * False (and counted in lost()) when it could not be written; never throws.
     */
    public function append(ErrorEvent $scrubbed, ?string $fingerprint = null): bool
    {
        if (! ErrorRecorder::enabled()) {
            return false;
        }

        try {
            $line = self::encode(self::spoolable($scrubbed, $fingerprint));
            $written = $this->locked(self::path(), create: true, work: static function ($handle) use ($line): bool {
                $stat = fstat($handle);
                $size = is_array($stat) ? $stat['size'] : null;

                if (! is_int($size)) {
                    return false;
                }

                // The last line of the file was cut short (a process died while writing it): end it first.
                $glue = $size > 0 && (fseek($handle, -1, SEEK_END) !== 0 || fread($handle, 1) !== "\n") ? "\n" : '';
                $bytes = $glue.$line;

                if ($size + strlen($bytes) > self::MAX_BYTES || fseek($handle, 0, SEEK_END) !== 0) {
                    return false;
                }

                if (fwrite($handle, $bytes) !== strlen($bytes)) {
                    ftruncate($handle, $size); // never leave half a line for the next one to be glued to

                    return false;
                }

                return fflush($handle);
            }) === true;
        } catch (Throwable) {
            $written = false;
        }

        if (! $written) {
            self::$lost++;
        }

        return $written;
    }

    /**
     * Records the lines through the recorder, oldest first, and removes what it took out. Returns how many it recorded
     * (an issue opened, counted or reopened); dropped() says how many it took out without recording. Never throws.
     *
     * @param  float|null  $seconds  the time budget (IMPORT_SECONDS unless told); at least one line is always tried
     */
    public function import(ErrorRecorder $recorder, ?float $seconds = null): int
    {
        $this->dropped = 0;
        $path = self::path();

        if (! ErrorRecorder::enabled() || ErrorRecorder::busy() || ! is_file($path)) {
            return 0;
        }

        $mutex = @fopen($path.'.lock', 'c');

        if ($mutex === false) {
            return 0;
        }

        try {
            if (! flock($mutex, LOCK_EX | LOCK_NB)) {
                return 0; // another import is at work
            }

            try {
                return $this->drain($path, $recorder, $seconds ?? self::IMPORT_SECONDS);
            } finally {
                flock($mutex, LOCK_UN);
            }
        } catch (Throwable) {
            return 0;
        } finally {
            fclose($mutex);
            $recorder->countLost();
        }
    }

    private function drain(string $path, ErrorRecorder $recorder, float $seconds): int
    {
        $read = $this->locked($path, create: false, work: static fn ($handle) => stream_get_contents($handle));

        if (! is_string($read) || $read === '') {
            return 0;
        }

        $lines = explode("\n", $read);
        $deadline = microtime(true) + max(0.0, $seconds);
        $handled = 0;
        $imported = 0;
        $skipped = 0; // taken out, not recorded: the recorder dropped it or the issue is ignored
        $lost = 0; // taken out and lost: unreadable, or refused too often (counted as dropped noise)
        $kept = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if ($handled > 0 && microtime(true) >= $deadline) {
                $kept = self::rest($lines, $index);

                break;
            }

            $handled++;
            $data = self::decode($line);
            $event = $data === null ? null : self::event($data);

            if ($data === null || $event === null) {
                $lost++; // not a line anybody can read

                continue;
            }

            try {
                $recorded = $recorder->replay($event, is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : null);
            } catch (RecorderBusy) {
                $kept = self::rest($lines, $index); // not refused, only not now: kept as it is, no try counted

                break;
            } catch (Throwable) {
                $recorded = null; // refused: see below whether by a database that is away or for this line alone
            }

            if ($recorded !== null) {
                if (in_array($recorded, self::RECORDED, true)) {
                    $imported++;
                } else {
                    $skipped++;
                }

                continue;
            }

            if (! $this->tablesAnswer()) {
                $kept = self::rest($lines, $index); // still away: this line and the rest wait, untouched

                break;
            }

            $tries = (is_int($data['tries'] ?? null) ? $data['tries'] : 0) + 1;

            if ($tries >= self::MAX_TRIES) {
                $lost++;

                continue;
            }

            $kept = [rtrim(self::encode(['tries' => $tries] + $data), "\n"), ...self::rest($lines, $index + 1)];

            break;
        }

        $lost += $this->putBack($path, $read, $kept);
        $this->dropped = $skipped + $lost;

        if ($lost > 0) {
            $recorder->noise('dropped', null, $lost);
        }

        return $imported;
    }

    /**
     * Writes back what was not recorded, followed by what was appended since the file was read. Returns how many lines did
     * not fit under the cap (a kept line can grow by its `tries`).
     *
     * @param  list<string>  $kept
     */
    private function putBack(string $path, string $read, array $kept): int
    {
        $cut = $this->locked($path, create: false, work: static function ($handle) use ($read, $kept): int {
            rewind($handle);
            $now = stream_get_contents($handle);
            $now = is_string($now) ? $now : '';

            // Only appends happen while an import holds its lock, so the file still starts with what was read.
            $tail = str_starts_with($now, $read) ? substr($now, strlen($read)) : $now;
            [$content, $cut] = self::fit(($kept === [] ? '' : implode("\n", $kept)."\n").$tail);

            if (! ftruncate($handle, 0) || ! rewind($handle)) {
                return 0;
            }

            fwrite($handle, $content);
            fflush($handle);

            return $cut;
        });

        return is_int($cut) ? $cut : 0;
    }

    /**
     * Do the error tables answer a read? Inside a transaction of the recorder's own (a savepoint when nested), so a refused
     * read never spoils a caller's PostgreSQL transaction.
     */
    private function tablesAnswer(): bool
    {
        try {
            $db = (new ErrorIssue)->getConnection();
            ErrorRecorder::transaction($db, function () use ($db): void {
                foreach (self::TABLES as $table) {
                    $db->table($table)->limit(1)->exists();
                }
            });

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Opens the file, takes its lock and runs $work with the handle. Null when it cannot be opened or locked.
     *
     * @template T
     *
     * @param  Closure(resource): T  $work
     * @return T|null
     */
    private function locked(string $path, bool $create, Closure $work): mixed
    {
        if ($create && ! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            return null;
        }

        $handle = @fopen($path, $create ? 'c+b' : 'r+b');

        if ($handle === false) {
            return null;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return null;
            }

            try {
                return $work($handle);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The line of an event: scrubbed (again), with the person hash in place of the user id, and its fingerprint.
     *
     * @return array<string, mixed>
     */
    private static function spoolable(ErrorEvent $event, ?string $fingerprint): array
    {
        $scrubbed = ErrorScrubber::event($event);
        $fingerprint = $fingerprint !== null && preg_match('~^[0-9a-f]{40}$~D', $fingerprint) === 1 ? $fingerprint : ErrorFingerprint::of($scrubbed);

        return ['user_id' => ErrorRecorder::spooledPerson($event->userId)] + $scrubbed->toArray() + ['fingerprint' => $fingerprint];
    }

    /** @param  array<array-key, mixed>  $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array<array-key, mixed>|null */
    private static function decode(string $line): ?array
    {
        try {
            $data = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @param  array<array-key, mixed>  $data */
    private static function event(array $data): ?ErrorEvent
    {
        try {
            return ErrorEvent::fromArray($data);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The lines from $from on, without the empty ones.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function rest(array $lines, int $from): array
    {
        return array_values(array_filter(array_map(trim(...), array_slice($lines, $from)), static fn (string $line): bool => $line !== ''));
    }

    /**
     * The content cut to the cap at a line break, and how many lines that dropped (the newest ones).
     *
     * @return array{0: string, 1: int}
     */
    private static function fit(string $content): array
    {
        if (strlen($content) <= self::MAX_BYTES) {
            return [$content, 0];
        }

        $end = strrpos(substr($content, 0, self::MAX_BYTES), "\n");
        $keep = $end === false ? '' : substr($content, 0, $end + 1);
        $removed = array_filter(explode("\n", substr($content, strlen($keep))), static fn (string $line): bool => trim($line) !== '');

        return [$keep, count($removed)];
    }
}
