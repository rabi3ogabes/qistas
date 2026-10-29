<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One close of one day (daily-habit wave A, H4): the cash a person counted set against what Qistas recorded for the
 * workspace's day, signed off, with the day's figures as they were then. Written only by trusted code (nothing is
 * mass-assignable), never from request data.
 *
 * It is a record of what was signed, so it is never edited. The one change a row takes is to be reopened: its status
 * moves, and who reopened it, when and why are written beside it. Closing the day again writes the next version.
 * Nothing deletes it.
 *
 * @property string $day the workspace's own calendar day, Y-m-d (a plain string: never cut at the database's date)
 * @property int $version
 * @property string $status closed | reopened
 * @property string $expected_cash what was recorded in cash for the day
 * @property string $counted_cash what the person counted
 * @property string $difference counted less expected: short is negative, over is positive
 * @property array<string, mixed> $totals by_method, count and per_member, as DayTotals worked them out; the names in
 *                                        per_member are a record of what the screen showed (in the closer's language)
 * @property string|null $note
 * @property string|null $closed_by_user_id
 * @property Carbon $closed_at
 * @property string|null $reopened_by_user_id
 * @property Carbon|null $reopened_at
 * @property string|null $reopen_reason
 * @property string $snapshot_hash a SHA-256 of the day's figures when it was closed, names left out (CloseDay::snapshotHash)
 */
class DayClose extends Model
{
    use BelongsToTenant, HasUuids;

    /** The setting that holds when the evening reminder to close the day goes out, on the workspace's own clock. */
    public const CLOSING_TIME_SETTING = 'day_close.closing_time';

    /** The times of day the owner can choose from, every half hour from 17:00 to 23:30. */
    public const CLOSING_TIMES = [
        '17:00', '17:30', '18:00', '18:30', '19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '22:00', '22:30', '23:00', '23:30',
    ];

    /** Until the owner chooses another. */
    public const DEFAULT_CLOSING_TIME = '20:00';

    /** The only columns a saved row may change: reopening it (and the timestamp the framework keeps). */
    private const REOPENING = ['status', 'reopened_by_user_id', 'reopened_at', 'reopen_reason', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $close): void {
            if (array_diff(array_keys($close->getDirty()), self::REOPENING) !== []) {
                throw new LogicException('A close is a signed record and is never edited; reopen the day and close it again.');
            }
        });
        static::deleting(fn () => throw new LogicException('A close is a signed record and cannot be deleted; reopen the day instead.'));
    }

    /**
     * Who counted the cash and signed the day off; null once that person has left the team (the close stays as it was).
     *
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * Who reopened the day, once it has been; null otherwise, and when that person has left the team.
     *
     * @return BelongsTo<User, $this>
     */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'expected_cash' => 'decimal:4',
            'counted_cash' => 'decimal:4',
            'difference' => 'decimal:4',
            'totals' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
