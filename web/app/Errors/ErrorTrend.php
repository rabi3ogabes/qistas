<?php

declare(strict_types=1);

namespace App\Errors;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What the error center reads from its daily counts, and the one rule for "growing" (design sections 7 and 8). The admin
 * list draws its sparklines and its marks from days() and growing(); the daily digest reuses both, so the rule lives here
 * and nowhere else.
 *
 * The recorder writes a day on the application clock (`app.timezone`), so a day is read on that clock too. Every helper
 * is a read: nothing here changes a table.
 */
final class ErrorTrend
{
    /** "Growing" compares today with the average of this many days before it. */
    public const WINDOW = 7;

    /** Today must happen at least this often to be called growing, however quiet the week before was. */
    public const GROWING_MIN = 3;

    /** Today must be at least this many times the daily average of the week before. */
    public const GROWING_TIMES = 2;

    /** How many issue ids one query asks for, so a long list stays under the database's limit on parameters. */
    private const CHUNK = 500;

    /**
     * The last $days daily counts of each issue, oldest first, today last, a day with no sighting as 0. One query for all
     * the issues (a page of them), not one each. An id that has no days at all gets zeros; a repeated id is read once.
     * "Today" is the day of $now on the application clock (now when not given), so a digest made for another moment reads
     * the week of that moment, not of the real clock.
     *
     * @param  iterable<string>  $issueIds
     * @return array<string, list<int>> issue id => counts
     */
    public static function days(iterable $issueIds, int $days = 7, ?CarbonInterface $now = null): array
    {
        $days = max(1, $days);
        $ids = [];

        foreach ($issueIds as $id) {
            $ids[(string) $id] = true;
        }

        if ($ids === []) {
            return [];
        }

        $today = CarbonImmutable::instance($now ?? now())->setTimezone(self::timezone())->startOfDay();
        $slots = [];

        for ($back = $days - 1; $back >= 0; $back--) {
            $slots[$today->subDays($back)->toDateString()] = 0;
        }

        $series = array_map(fn (): array => $slots, $ids);
        $first = array_key_first($slots);
        $last = array_key_last($slots);

        foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
            $rows = DB::table('error_issue_days')->whereIn('issue_id', $chunk)->whereBetween('day', [$first, $last])->get(['issue_id', 'day', 'count']);

            foreach ($rows as $row) {
                $day = substr((string) $row->day, 0, 10);

                if (isset($series[$row->issue_id][$day])) {
                    $series[$row->issue_id][$day] = (int) $row->count;
                }
            }
        }

        return array_map(fn (array $counts): array => array_values($counts), $series);
    }

    /**
     * Is an issue growing? Today at least GROWING_MIN times, and at least GROWING_TIMES times the daily average of the
     * previous seven days. A week with fewer days than that counts the missing ones as quiet, and only the last seven are
     * read. Whole numbers all the way, so no rounding decides it.
     *
     * @param  list<int>  $previousSevenDays  the seven days before today, oldest first
     */
    public static function growing(int $today, array $previousSevenDays): bool
    {
        $week = array_sum(array_slice($previousSevenDays, -self::WINDOW));

        return $today >= self::GROWING_MIN && $today * self::WINDOW >= self::GROWING_TIMES * $week;
    }

    /**
     * Today's count and the seven days before it, from days() read WINDOW + 1 days at a time (oldest first, today last):
     * what growing() is given. Fewer days give what there is; more give only the seven before today.
     *
     * @param  list<int>  $eightDays
     * @return array{0: int, 1: list<int>}
     */
    public static function split(array $eightDays): array
    {
        $days = $eightDays;
        $today = array_pop($days) ?? 0;

        return [$today, array_slice($days, -self::WINDOW)];
    }

    /**
     * What was counted as noise (a scanner's request, or a report over a flood cap) on each of the last $days days, newest
     * day first, a quiet day as zeros. The sums are what the admin's "Background noise" card shows.
     *
     * @return list<array{day: string, scanner: int, dropped: int}>
     */
    public static function noise(int $days = 7): array
    {
        $days = max(1, $days);
        $today = CarbonImmutable::now(self::timezone())->startOfDay();
        $rows = [];

        for ($back = 0; $back < $days; $back++) {
            $rows[$today->subDays($back)->toDateString()] = ['day' => $today->subDays($back)->toDateString(), 'scanner' => 0, 'dropped' => 0];
        }

        $found = DB::table('error_noise_days')->whereBetween('day', [array_key_last($rows), array_key_first($rows)])->get(['day', 'kind', 'count']);

        foreach ($found as $row) {
            $day = substr((string) $row->day, 0, 10);

            if (isset($rows[$day]) && in_array($row->kind, ['scanner', 'dropped'], true)) {
                $rows[$day][$row->kind] = (int) $row->count;
            }
        }

        return array_values($rows);
    }

    private static function timezone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
