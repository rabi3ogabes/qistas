<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One calendar day on a workspace's own clock, as the two UTC instants that bound it. A payment at 23:50 in Riyadh belongs
 * to that Riyadh day even though it is already tomorrow in UTC, so anything that is "per day" (closing the day, the
 * morning digest, a payday count) asks the day for its range instead of cutting at UTC midnight.
 *
 * [$start] is the first instant of the day and [$end] the first instant of the next one, so a day is the half-open range
 * [$start, $end): it is 23 or 25 hours long when the clocks change, and a day's end is exactly the next day's start. Both
 * are UTC. [$date] is the local day as Y-m-d; [$timezone] is the zone it was cut in.
 */
final readonly class WorkspaceDay
{
    public function __construct(public string $date, public CarbonImmutable $start, public CarbonImmutable $end, public string $timezone) {}

    /** The workspace's day that contains [$now] (the current moment when none is given). */
    public static function of(Tenant $tenant, ?CarbonInterface $now = null): self
    {
        // A copy, because localTime() moves the zone of the moment it is handed, and that is the caller's own.
        $local = $tenant->localTime(CarbonImmutable::instance($now ?? now()));

        return self::build($local->format('Y-m-d'), $tenant->localTimezone());
    }

    /** The workspace's day for [$date] (Y-m-d on the workspace's own calendar). */
    public static function on(Tenant $tenant, string $date): self
    {
        return self::build($date, $tenant->localTimezone());
    }

    /** Whether [$moment] falls in this day: from its start, up to but not including its end. */
    public function contains(CarbonInterface $moment): bool
    {
        return $moment->greaterThanOrEqualTo($this->start) && $moment->lessThan($this->end);
    }

    /** The day before this one, on the same clock. */
    public function previous(): self
    {
        return self::build(CarbonImmutable::parse($this->date, 'UTC')->subDay()->format('Y-m-d'), $this->timezone);
    }

    /**
     * [$hhmm] ("20:00") on this day's wall clock, as a UTC instant. A time the clocks skip over that day (02:30 when they
     * go forward) lands an hour later, as PHP resolves it.
     */
    public function atTime(string $hhmm): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date.' '.$hhmm, $this->timezone)->utc();
    }

    private static function build(string $date, string $timezone): self
    {
        $first = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $day = $first->format('Y-m-d');
        // The next day is found on the calendar, not by adding 24 hours, which is wrong on the day the clocks change.
        $next = CarbonImmutable::parse($day, 'UTC')->addDay()->format('Y-m-d');

        return new self($day, $first->utc(), CarbonImmutable::parse($next, $timezone)->startOfDay()->utc(), $timezone);
    }
}
