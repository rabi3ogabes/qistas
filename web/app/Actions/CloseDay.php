<?php

namespace App\Actions;

use App\Domain\DayClose\DayTotals;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Closes one of the workspace's days (daily-habit wave A, H4): the cash a person counted is set against what was
 * recorded for the day (DayTotals), and the day's figures are kept as they were then, signed with a hash. The one place
 * a close is written. Nothing in the ledger is touched.
 *
 * A day is closed once: a second close, from a double tap or a second phone, is refused. After an owner or a manager
 * reopens it (ReopenDay), closing it again writes the next version.
 */
final class CloseDay
{
    /** Today and the seven days before it can be closed (the message names the seven). */
    private const DAYS_BACK = 7;

    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * In this order: may the workspace and [$by] close a day at all; is what they typed right; then the write, which
     * takes the day's lock, finds the day not yet closed, and reads the figures it keeps.
     *
     * @param  string  $countedCash  what is in the till: not negative, at most two decimals
     * @param  ?string  $note  anything the person wants kept with the close
     *
     * @throws FeatureUnavailable|FeatureLocked when the workspace does not have the end-of-day close
     * @throws AuthorizationException when [$by] does not record payments in [$tenant] (a viewer, or someone from elsewhere)
     * @throws ValidationException on `day` (a day still to come, more than seven days back, or already closed) or `counted_cash`
     */
    public function handle(Tenant $tenant, WorkspaceDay $day, string $countedCash, ?string $note, User $by): DayClose
    {
        return $this->current->use($tenant, function () use ($tenant, $day, $countedCash, $note, $by): DayClose {
            Entitlements::for($tenant)->assertEnabled(Feature::DayClose);
            Gate::forUser($by)->authorize('create', DayClose::class);

            $counted = $this->counted($tenant, $day, $countedCash);
            $note = $note === null || trim($note) === '' ? null : trim($note);

            try {
                return DB::transaction(fn (): DayClose => $this->write($tenant, $day, $counted, $note, $by));
            } catch (UniqueConstraintViolationException) {
                // Another close of the day was written between this one's look at the day and its own write (the first
                // close of a day has no row to lock): the key (tenant, day, version) let one through, and it was not this.
                throw self::alreadyClosed();
            }
        });
    }

    /**
     * The SHA-256 a close is signed with, so a later reading can tell whether its figures were touched.
     *
     * It is worked out from the figures alone: the day, the version, the expected and counted cash, the total of each
     * method, the count, and each member's cash and count keyed by user id, as JSON with every key sorted and every
     * amount at two decimals. The members' names are left out: they are display data, a record of what the screen
     * showed, and the name of someone who has left the team is written in the language of whoever closed the day, so
     * the same day closed in English and in Arabic is signed the same. It can be worked out again from a stored row.
     *
     * @param  array<string, mixed>  $totals  `by_method`, `count` and `per_member` as DayTotals gives them, or as a close stores them
     */
    public static function snapshotHash(string $day, int $version, string $expectedCash, string $countedCash, array $totals): string
    {
        /** @var array<string, string> $byMethod */
        $byMethod = $totals['by_method'] ?? [];
        /** @var array<string, array{cash: string, count: int}> $perMember */
        $perMember = $totals['per_member'] ?? [];

        $members = [];
        foreach ($perMember as $id => $member) {
            $members[(string) $id] = ['cash' => self::amount($member['cash']), 'count' => (int) $member['count']];
        }

        $figures = [
            'day' => $day,
            'version' => $version,
            'expected_cash' => self::amount($expectedCash),
            'counted_cash' => self::amount($countedCash),
            'by_method' => array_map(self::amount(...), $byMethod),
            'count' => (int) ($totals['count'] ?? 0),
            'per_member' => $members,
        ];

        return hash('sha256', json_encode(self::sorted($figures), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** The counted cash at two decimals; refuses a day out of reach and an amount that cannot be counted. */
    private function counted(Tenant $tenant, WorkspaceDay $day, string $countedCash): string
    {
        $errors = [];

        $today = WorkspaceDay::of($tenant)->date;
        if ($day->date > $today) {
            $errors['day'] = __('A day that has not come yet cannot be closed.');
        } elseif ($day->date < CarbonImmutable::parse($today, 'UTC')->subDays(self::DAYS_BACK)->format('Y-m-d')) {
            $errors['day'] = __('Only today and the seven days before it can be closed.');
        }

        try {
            $parsed = Money::parse($countedCash);
            if (Money::isNegative($parsed)) {
                $errors['counted_cash'] = __('The counted cash cannot be negative.');
            } elseif (Money::decimals($parsed) > 2) {
                $errors['counted_cash'] = __('The amount can have at most two decimals.');
            }
        } catch (InvalidArgumentException) {
            $errors['counted_cash'] = __('Enter a valid amount.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return Money::add(Money::parse($countedCash), '0', 2);
    }

    private function write(Tenant $tenant, WorkspaceDay $day, string $counted, ?string $note, User $by): DayClose
    {
        // Closes and reopenings of one day take turns on its latest version: a second close waits here, then finds the
        // day closed. The day's very first close has no row to wait on; the unique key decides that race (see handle).
        $latest = DayClose::query()->where('day', $day->date)->orderByDesc('version')->lockForUpdate()->first();
        if ($latest !== null && $latest->status === 'closed') {
            throw self::alreadyClosed();
        }
        $version = $latest === null ? 1 : $latest->version + 1;

        // Read after the lock and inside the transaction: the close that is written keeps the figures it read.
        $recorded = DayTotals::for($tenant, $day);
        $expected = $recorded['expected_cash'];
        // The names in `per_member` are kept as display data, a record of what the screen showed; the hash leaves them out.
        $totals = ['by_method' => $recorded['by_method'], 'count' => $recorded['count'], 'per_member' => $recorded['per_member']];
        $difference = Money::sub($counted, $expected, 2);

        $close = (new DayClose)->forceFill([
            'day' => $day->date,
            'version' => $version,
            'status' => 'closed',
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => $difference,
            'totals' => $totals,
            'note' => $note,
            'closed_by_user_id' => $by->id,
            'closed_at' => now(),
            'snapshot_hash' => self::snapshotHash($day->date, $version, $expected, $counted, $totals),
        ]);
        $close->save();

        Audit::record('day_close.closed', $close, [
            'day' => $day->date, 'version' => $version, 'expected_cash' => $expected, 'counted_cash' => $counted, 'difference' => $difference,
        ], $tenant->id, $by->id);

        return $close;
    }

    private static function alreadyClosed(): ValidationException
    {
        return ValidationException::withMessages(['day' => __('This day is already closed.')]);
    }

    private static function amount(string $value): string
    {
        return Money::add($value, '0', 2);
    }

    /**
     * [$value] with the keys of every map sorted, so the same figures always give the same text.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
