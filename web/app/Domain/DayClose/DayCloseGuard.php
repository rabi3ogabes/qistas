<?php

namespace App\Domain\DayClose;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The after-close rule (daily-habit wave A, H4, decision D10): once a day is closed, a change that touches it is an
 * owner's or a manager's to make, and it leaves an audit row. Nothing in the ledger is edited: the rule decides only who
 * may add the line. Every entry point that writes money asks it first, before any write (RecordPayment::handle and
 * ::settle, RecordCustomerPayment, VoidTransaction, and CreateContract for a down payment).
 *
 * What touches a closed day:
 * - a payment dated before the workspace's today (back-dated) on a day that is closed;
 * - a void, which touches two days: the one its payment was taken on, and today, when the reversal is recorded and the
 *   cash it gives back leaves the till (DayTotals counts a void on the day it is recorded).
 * A payment dated today is never refused, whoever takes it: a shop keeps selling after it has counted the till. When
 * today is closed it is let through and noted (`day_close.entry_after_close`), so the owner can see what came in after
 * the count.
 *
 * Only payments and voids are guarded; a charge on an open account is owed money, never cash, and moves no closed figure.
 * While the workspace does not have the end-of-day close (switched off), nothing is guarded: the closes stay as records,
 * the screens that would reopen them are gone, and the rule comes back with the feature.
 */
final class DayCloseGuard
{
    /** Money taken: on one contract, an early settlement, or one payment across a customer's contracts. */
    public const PAYMENT = 'payment';

    /** A payment voided. */
    public const VOID = 'void';

    /** Whether [$day] is closed: its latest close stands (one that was reopened leaves the day open until it is closed again). */
    public static function isClosed(Tenant $tenant, WorkspaceDay $day): bool
    {
        return self::closed($tenant, [$day->date]) !== [];
    }

    /**
     * Lets [$by] make the change, or refuses it, before anything is written.
     *
     * @param  CarbonInterface  $moment  when the money was taken: the new payment's `paid_at` (the very instant its ledger
     *                                   line will carry), or for a void, the `paid_at` of the payment voided
     * @param  string  $change  what is about to be written: self::PAYMENT (the default) or self::VOID
     * @param  ?string  $field  the field a refusal names, when the person typed the date somewhere else (a contract's
     *                          down payment is dated by its `start_date`); by default `paid_at` for a payment and
     *                          `transaction` for a void
     *
     * @throws ValidationException on [$field] when the change touches a closed day and [$by] is not an owner or a manager
     */
    public static function assertMayChange(Tenant $tenant, CarbonInterface $moment, ?User $by, string $change = self::PAYMENT, ?string $field = null): void
    {
        if (! in_array($change, [self::PAYMENT, self::VOID], true)) {
            throw new InvalidArgumentException("[{$change}] is not a change the after-close rule knows.");
        }

        $today = WorkspaceDay::of($tenant);
        $taken = WorkspaceDay::of($tenant, $moment);

        // Money taken today adds to the day after its count; it is never refused, only noted.
        if ($change === self::PAYMENT && ! $moment->lessThan($today->start)) {
            foreach (self::guarded($tenant, [$taken->date]) as $close) {
                Audit::record('day_close.entry_after_close', $close, ['day' => $close->day], $tenant->id, $by?->id);
            }

            return;
        }

        $closes = self::guarded($tenant, $change === self::VOID ? array_unique([$taken->date, $today->date]) : [$taken->date]);
        if ($closes === []) {
            return;
        }

        if (! ($by?->roleIn($tenant->id)?->canDelete() ?? false)) {
            throw ValidationException::withMessages([
                $field ?? ($change === self::VOID ? 'transaction' : 'paid_at') => __('That day is closed. Ask the owner or a manager.'),
            ]);
        }

        foreach ($closes as $close) {
            Audit::record('day_close.change_after_close', $close, ['day' => $close->day, 'change' => $change], $tenant->id, $by->id);
        }
    }

    /**
     * The standing closes of [$dates] while the workspace has the feature; none while it is off.
     *
     * @param  array<int, string>  $dates
     * @return list<DayClose>
     */
    private static function guarded(Tenant $tenant, array $dates): array
    {
        $closes = self::closed($tenant, $dates);

        return $closes !== [] && Entitlements::for($tenant)->check(Feature::DayClose)->enabled() ? $closes : [];
    }

    /**
     * The closes that stand for [$dates], the earliest day first: each day's latest version, when it is not reopened.
     *
     * @param  array<int, string>  $dates
     * @return list<DayClose>
     */
    private static function closed(Tenant $tenant, array $dates): array
    {
        return app(CurrentTenant::class)->use($tenant, fn (): array => DayClose::query()
            ->whereIn('day', array_values($dates))
            ->orderBy('day')->orderByDesc('version')
            ->get()
            ->unique('day')
            ->where('status', 'closed')
            ->values()
            ->all());
    }
}
