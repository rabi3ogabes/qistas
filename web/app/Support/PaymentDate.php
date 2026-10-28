<?php

namespace App\Support;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What a date typed for a payment means. A date on its own ("2026-10-21") is a day on the WORKSPACE's calendar, not the
 * server's: read on the server's clock (UTC) it became 23:59:59 UTC, which is 02:59 the next day in Riyadh, so a payment
 * back-dated to yesterday landed in today's cash. Every place a person can type a payment's date asks this class, so the
 * ledger, the end-of-day close and the after-close guard all agree which day a payment belongs to.
 */
final class PaymentDate
{
    /**
     * The moment a payment typed as [$value] was received, always as a UTC instant.
     *
     * A date alone is that day on the workspace's calendar: right now when it is the workspace's today (whatever the hour
     * there), otherwise the last second of that day, as a UTC instant like those of {@see WorkspaceDay}. So it is never
     * in the future unless the day itself is.
     *
     * A date with a time is the instant it names. With an offset or a `Z` ("2026-10-20T12:00:00-14:00") the offset says
     * which instant; without one ("2026-10-20 12:00") it is read on the server's clock, UTC, as it always has been (the app
     * and the web forms send a date alone, so only an API client sends a time, and it can say which zone it means). The
     * instant is handed back in UTC because the database keeps a moment's digits, whatever zone they were written in: kept
     * as -14:00, noon on the 20th (02:00 UTC on the 21st) was stored as noon UTC on the 20th, so the after-close rule and
     * the future check judged one moment and the ledger kept another.
     */
    public static function moment(Tenant $tenant, string $value, ?CarbonInterface $now = null): CarbonInterface
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->utc();
        }

        $now ??= now();
        $day = WorkspaceDay::on($tenant, $value);

        return WorkspaceDay::of($tenant, $now)->date === $day->date ? $now : $day->end->subSecond();
    }

    /** Whether [$value] dates a payment after [$now]: a day after the workspace's today, or a moment later than now. */
    public static function isFuture(Tenant $tenant, string $value, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        // A typed day is compared as a date: the end of 9999-12-31 is built from year 10000, which reads back as 2000.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value > WorkspaceDay::of($tenant, $now)->date;
        }

        return self::moment($tenant, $value, $now)->greaterThan($now);
    }

    /**
     * The validation rule for the date field of a payment: refuses what {@see isFuture()} says is later than now, with
     * the message the ledger itself gives. It reads the date on the calendar of [$tenant], or of the current workspace
     * when none is given, so it goes after the field's own date rule (and `bail`, so a value that is not a date never
     * reaches it).
     */
    public static function notInFuture(?Tenant $tenant = null): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($tenant): void {
            if (! is_string($value)) {
                return;
            }

            $workspace = $tenant ?? app(CurrentTenant::class)->get() ?? throw new LogicException('A payment date is read on a workspace\'s calendar, and no workspace is current.');

            if (self::isFuture($workspace, $value)) {
                $fail(__('A payment cannot be dated in the future.'));
            }
        };
    }
}
