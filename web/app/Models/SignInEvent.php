<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * One line of a person's sign-in history, written by App\Auth\SignInRecorder; never edited or deleted by the app.
 *
 * It is owned by the person, not by a workspace: one person can belong to several, and their history is theirs
 * wherever they sign in. So, unlike the models that hold a business's data, it has no BelongsToTenant and no tenant_id,
 * and it is only ever read through User::signInEvents(), through the lists below (recent, recentFor: the person's own and
 * the ones platform staff see), or by the retention command. It stores nothing a person typed.
 *
 * Append-only like AuditLog: an instance refuses update and delete. Only `qistas:sign-ins:prune` removes rows, with a
 * query-builder delete that skips model events on purpose (see App\Console\Commands\PruneSignInsCommand).
 *
 * @property string $id
 * @property string $user_id
 * @property string $method password (otp, google and microsoft arrive later)
 * @property string $channel web or app
 * @property string $outcome success or failed
 * @property string $device_type desktop, mobile, tablet or unknown
 * @property string|null $os
 * @property string|null $browser
 * @property string|null $app_version
 * @property string|null $device_name
 * @property string $ip
 * @property string|null $ip_country
 * @property string $proxy not_detected, possible or detected
 * @property list<string> $proxy_signals tor, via_header, forwarded_chain, timezone_country_mismatch
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'method', 'channel', 'outcome', 'device_type', 'os', 'browser', 'app_version', 'device_name', 'ip', 'ip_country', 'proxy', 'proxy_signals', 'user_agent'])]
class SignInEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** How many sign-ins a person is shown of their own (the API and the security page), and how many of them may be wrong passwords. */
    public const PERSON_LIMIT = 20;

    public const PERSON_FAILED_CAP = 5;

    /** The same for each member of a business on the admin page. */
    public const STAFF_LIMIT = 10;

    public const STAFF_FAILED_CAP = 3;

    /**
     * The sign-ins every screen shows for these people, one rule in one place. Per person: the newest [$failedCap] wrong
     * passwords and the newest [$limit] real sign-ins, merged newest first (the id breaks a tie between two in the same
     * second, as User::signInEvents() does), the top [$limit] of them.
     *
     * Wrong passwords are capped because anyone who knows an e-mail address can write them: the one-a-minute throttle is
     * per account and address, so a stranger with twenty addresses could otherwise push every real sign-in (their own
     * included) off the screen. Real sign-ins always fill what the wrong passwords leave.
     *
     * One query however many people are asked for, and only bound parameters. It is standard SQL that SQLite and
     * PostgreSQL both run: ROW_NUMBER() numbers each person's rows within their outcome, the rows over the caps are
     * dropped, the rest are numbered again per person, and the first [$limit] are kept. A window function cannot be
     * filtered where it is computed, hence the three levels. Rows are numbered only for the people asked for.
     *
     * @param  array<array-key, string>|Collection<array-key, string>  $userIds
     * @return Collection<array-key, Collection<int, SignInEvent>> each person's rows, newest first, by user id; a person with none is absent
     */
    public static function recent(array|Collection $userIds, int $limit, int $failedCap): Collection
    {
        $ids = array_values(array_unique(array_map('strval', $userIds instanceof Collection ? $userIds->all() : $userIds)));
        $limit = max(0, $limit);
        $failedCap = max(0, $failedCap);

        if ($ids === [] || $limit === 0) {
            return new Collection;
        }

        $perOutcome = self::query()
            ->select('sign_in_events.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id, outcome ORDER BY created_at DESC, id DESC) AS kind_rank')
            ->whereIn('user_id', $ids);

        $kept = self::query()
            ->fromSub($perOutcome, 'per_outcome')
            ->select('per_outcome.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at DESC, id DESC) AS recency')
            ->whereRaw('((outcome = ? AND kind_rank <= ?) OR (outcome <> ? AND kind_rank <= ?))', ['failed', $failedCap, 'failed', $limit]);

        return self::query()
            ->fromSub($kept, 'kept')
            ->where('recency', '<=', $limit)
            ->orderBy('user_id')
            ->orderBy('recency')
            ->get()
            ->toBase()
            ->groupBy('user_id');
    }

    /**
     * What [$person] sees of their own history: the API's answer and the security page's list.
     *
     * @return Collection<int, SignInEvent> newest first
     */
    public static function recentFor(User $person): Collection
    {
        return self::recent([$person->id], self::PERSON_LIMIT, self::PERSON_FAILED_CAP)->get($person->id, new Collection);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sign-in records are append-only.'));
        static::deleting(fn () => throw new LogicException('Sign-in records are append-only.'));
    }

    protected function casts(): array
    {
        return ['proxy_signals' => 'array'];
    }
}
