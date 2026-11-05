<?php

namespace App\Console\Commands;

use App\Models\ErrorIssue;
use App\Models\ErrorOccurrence;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the error center forgets (design section 10), once a day:
 *
 *   - sample occurrences older than qistas.errors.occurrence_days (30 by default);
 *   - an open issue not seen for qistas.errors.issue_days (90 by default);
 *   - a resolved issue 30 days after it was resolved, and an ignored one 30 days after its last sighting;
 *   - the scanner-noise counts (error_noise_days) older than 30 days.
 *
 * An issue takes its days, its people and its remaining samples with it (the foreign keys cascade). The two settings are read
 * when the command runs, so a change needs no restart; and as `qistas:sign-ins:prune` does, it refuses to run when either is
 * below 1 (a zero would make the cut-off "now" and the whole history would go), removing nothing at all.
 *
 * Each status is its own query, so each is answered from the (status, last_seen_at) index and never reads the whole table, and
 * rows go in batches of BATCH ids: a statement is short whatever the backlog, no row is ever loaded into memory beyond its id, and
 * `DELETE ... LIMIT`, which PostgreSQL does not have, is not needed. The conditions are asked again with each delete, so an
 * issue that was seen again between choosing it and deleting it is left alone.
 *
 * When the cache is kept in the database (production), it also removes the expired rows the error center leaves there: the
 * recorder's counters (`errors:new-issues:*`, `errors:sender:*`), the repeat markers (`errors:hit:*`) and the alert limits
 * (`error-alerts-*`). Nothing reads an expired row of the database store again, so without this they would pile up for ever.
 * Only keys that start, after the cache prefix, with exactly those names are touched, so no other feature's cache row is.
 */
final class ErrorsPruneCommand extends Command
{
    /** Rows removed by one statement. */
    public const BATCH = 1000;

    /** How long a resolved or ignored issue and the noise counts are kept: not a setting, a rule of the design. */
    private const KEPT_DAYS = 30;

    protected $signature = 'qistas:errors:prune';

    protected $description = 'Delete old error samples, issues and scanner-noise counts, and expired cache rows of the error center';

    public function handle(): int
    {
        $occurrenceDays = self::setting('occurrence_days');
        $issueDays = self::setting('issue_days');
        $refused = false;

        foreach ([
            'occurrence_days' => ['QISTAS_ERROR_OCCURRENCE_DAYS', $occurrenceDays],
            'issue_days' => ['QISTAS_ERROR_ISSUE_DAYS', $issueDays],
        ] as $name => [$variable, $days]) {
            if ($days < 1) {
                $this->components->error("qistas.errors.{$name} ({$variable}) must be a whole number of days, at least 1.");
                $refused = true;
            }
        }

        if ($refused) {
            $this->line('Nothing was removed.');

            return self::FAILURE;
        }

        $now = now();
        $kept = $now->copy()->subDays(self::KEPT_DAYS);

        // Samples first: what is left of an issue's samples is then taken along with the issue by the cascade.
        $occurrenceCutoff = $now->copy()->subDays($occurrenceDays);
        $occurrences = $this->purge(fn (): Builder => ErrorOccurrence::query()->toBase()->where('occurred_at', '<', $occurrenceCutoff));

        $openCutoff = $now->copy()->subDays($issueDays);
        $open = $this->purge(fn (): Builder => ErrorIssue::query()->toBase()
            ->where('status', ErrorIssue::STATUS_OPEN)->where('last_seen_at', '<', $openCutoff));
        $resolved = $this->purge(fn (): Builder => ErrorIssue::query()->toBase()
            ->where('status', ErrorIssue::STATUS_RESOLVED)->where('resolved_at', '<', $kept));
        $ignored = $this->purge(fn (): Builder => ErrorIssue::query()->toBase()
            ->where('status', ErrorIssue::STATUS_IGNORED)->where('last_seen_at', '<', $kept));

        // At most two rows a day (scanner, dropped), so one statement is enough; `day` is a date, compared as one.
        $noise = DB::table('error_noise_days')->where('day', '<', $kept->toDateString())->delete();

        $this->components->info('Removed '.self::count($occurrences, 'occurrence').' older than '.self::days($occurrenceDays).'.');
        $this->components->info('Removed '.self::count($open, 'open issue').' not seen for '.self::days($issueDays).'.');
        $this->components->info('Removed '.self::count($resolved, 'resolved issue').' resolved more than '.self::days(self::KEPT_DAYS).' ago.');
        $this->components->info('Removed '.self::count($ignored, 'ignored issue').' not seen for '.self::days(self::KEPT_DAYS).'.');
        $this->components->info('Removed '.self::count($noise, 'noise row').' older than '.self::days(self::KEPT_DAYS).'.');

        $cache = $this->pruneCache();

        if ($cache !== null) {
            $this->components->info('Removed '.self::count($cache, 'expired error-center row').' from the cache.');
        }

        return self::SUCCESS;
    }

    /**
     * Removes the rows $matching selects, a batch of keys at a time. Returns how many went.
     *
     * @param  Closure(): Builder  $matching  a new query for the rows to remove, each time it is called
     */
    private function purge(Closure $matching, string $key = 'id'): int
    {
        $removed = 0;

        do {
            $keys = $matching()->limit(self::BATCH)->pluck($key)->all();

            if ($keys === []) {
                break;
            }

            // The conditions are part of the delete too: a row that changed since it was chosen is no longer one to remove.
            $gone = $matching()->whereIn($key, $keys)->delete();
            $removed += $gone;
        } while (count($keys) === self::BATCH && $gone > 0);

        return $removed;
    }

    /**
     * The expired rows of the error center in the cache table, or null when the cache is not kept in the database. The table,
     * the connection and the key prefix are the ones the cache itself uses.
     */
    private function pruneCache(): ?int
    {
        $store = config('cache.default');
        $settings = is_string($store) ? config("cache.stores.{$store}") : null;

        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'database') {
            return null;
        }

        $table = $settings['table'] ?? null;

        if (! is_string($table) || $table === '') {
            return null;
        }

        $prefix = $settings['prefix'] ?? config('cache.prefix');
        $prefix = is_string($prefix) ? $prefix : '';
        $connection = DB::connection(is_string($settings['connection'] ?? null) ? $settings['connection'] : null);
        $column = $connection->getQueryGrammar()->wrap('key');
        $now = now()->getTimestamp();

        // "Starts with", compared exactly: a prefix may hold "_" or "%", which LIKE would take for wildcards. Only expired rows
        // are looked at (the expiration column has an index), as the cache itself counts them: it expires on that second.
        $starts = [$prefix.'errors:', $prefix.'error-alerts-'];

        return $this->purge(fn (): Builder => $connection->table($table)
            ->where('expiration', '<=', $now)
            ->where(function (Builder $query) use ($starts, $column): void {
                foreach ($starts as $start) {
                    $query->orWhereRaw('substr('.$column.', 1, '.mb_strlen($start).') = ?', [$start]);
                }
            }), 'key');
    }

    /** The setting as a whole number of days, or 0 when it is not a number (the command then refuses to run). */
    private static function setting(string $name): int
    {
        $value = config("qistas.errors.{$name}");

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function count(int $number, string $noun): string
    {
        return $number.' '.$noun.($number === 1 ? '' : 's');
    }

    private static function days(int $number): string
    {
        return $number === 1 ? '1 day' : "{$number} days";
    }
}
