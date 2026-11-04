<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One kind of failure the error center has seen: every occurrence with the same fingerprint (see the error center
 * design, section 4) is counted here. Platform-wide, like SignInEvent and AuditLog: platform code writes it, so there is
 * no BelongsToTenant and no tenant_id, and nothing in it names a business or a person.
 *
 * Only what describes the sighting is mass assignable. The status, the counters and who triaged it are set by trusted
 * code only: `App\Errors\ErrorRecorder` (counts, reopening) and `App\Errors\ErrorTriage` (resolve, ignore, reopen).
 *
 * The `occurrences` attribute is the running count of sightings; the kept sightings are the relation `samples()`. The two
 * are named apart on purpose: a relation called `occurrences` would be shadowed by the attribute on property access and,
 * once loaded, would silently replace the count in `toArray()`.
 *
 * @property string $id
 * @property string $fingerprint sha1 of source, kind, class, normalised message and top frame
 * @property string $source site, api, database or app
 * @property string $kind exception, http, slow_query, script or crash
 * @property string $title
 * @property string|null $class
 * @property string $status open, resolved or ignored
 * @property int $occurrences
 * @property int $people how many different people it touched, capped at 500
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property string|null $first_version
 * @property string|null $last_version
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by a staff user's id
 * @property Carbon|null $ignored_at
 * @property string|null $ignored_by a staff user's id
 * @property Carbon|null $reopened_at
 * @property string|null $reopened_version the version it came back in
 * @property Carbon|null $alerted_at when the instant e-mail went
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['fingerprint', 'source', 'kind', 'title', 'class', 'first_seen_at', 'last_seen_at', 'first_version', 'last_version'])]
class ErrorIssue extends Model
{
    use HasUuids;

    public const SOURCES = ['site', 'api', 'database', 'app'];

    public const KINDS = ['exception', 'http', 'slow_query', 'script', 'crash'];

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_RESOLVED, self::STATUS_IGNORED];

    /**
     * The sample sightings, newest first: the last five, the recorder deletes the oldest when it writes a sixth.
     *
     * @return HasMany<ErrorOccurrence, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(ErrorOccurrence::class, 'issue_id')->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * New and untouched: not resolved, not ignored.
     *
     * @param  Builder<ErrorIssue>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN);
    }

    /** How many issues are waiting for a look; the badge on the admin menu. */
    public static function openCount(): int
    {
        return static::query()->open()->count();
    }

    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'people' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ignored_at' => 'datetime',
            'reopened_at' => 'datetime',
            'alerted_at' => 'datetime',
        ];
    }
}
