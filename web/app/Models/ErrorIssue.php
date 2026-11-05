<?php

namespace App\Models;

use App\Errors\ErrorRecorder;
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

    /** An issue is "new" for this many hours after it was first seen. */
    public const NEW_HOURS = 24;

    /** An open issue "came back" for this many days after the recorder reopened it. */
    public const BACK_DAYS = 7;

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

    /**
     * First seen within the last NEW_HOURS hours: the "new" mark and the "new in 24 hours" filter.
     *
     * @param  Builder<ErrorIssue>  $query
     */
    public function scopeNewIssues(Builder $query): void
    {
        $query->where('first_seen_at', '>=', now()->subHours(self::NEW_HOURS));
    }

    /**
     * Open again after being resolved, within the last BACK_DAYS days: the "came back" mark and filter. An issue that was
     * resolved (or ignored) once more since is not back, it is dealt with.
     *
     * @param  Builder<ErrorIssue>  $query
     */
    public function scopeCameBack(Builder $query): void
    {
        $query->where('status', self::STATUS_OPEN)->where('reopened_at', '>=', now()->subDays(self::BACK_DAYS));
    }

    /**
     * The server's own failures: an exception, or an error page of 500 or more (its class is `http.5xx`). What a visitor can
     * make at will (a 404 for a made-up address, an expired form), a slow query and what a browser or a phone reports are
     * not, so they never turn the admin home's line yellow.
     *
     * @param  Builder<ErrorIssue>  $query
     */
    public function scopeServerErrors(Builder $query): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('kind', 'exception')
            ->orWhere(fn (Builder $page) => $page->where('kind', 'http')->where('class', 'like', 'http.5%')));
    }

    /** Is it new? The same rule as scopeNewIssues. */
    public function isNew(): bool
    {
        return $this->first_seen_at->greaterThanOrEqualTo(now()->subHours(self::NEW_HOURS));
    }

    /** Did it come back? The same rule as scopeCameBack. */
    public function hasComeBack(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->reopened_at !== null
            && $this->reopened_at->greaterThanOrEqualTo(now()->subDays(self::BACK_DAYS));
    }

    /** How many different people it touched, as the screens say it: the cap is "500+", since the count stops growing there. */
    public function peopleLabel(): string
    {
        return $this->people >= ErrorRecorder::PEOPLE ? ErrorRecorder::PEOPLE.'+' : (string) $this->people;
    }

    /**
     * The sources in the words the screens use, by key. "Website", not "Site": a string that is also the name of a language
     * file (lang/en/site.php) cannot be translated (see TranslationsTest).
     *
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return ['site' => __('Website'), 'api' => __('API'), 'database' => __('Database'), 'app' => __('App')];
    }

    /** @return array<string, string> the kinds in plain words, by key */
    public static function kindLabels(): array
    {
        return [
            'exception' => __('Exception'), 'http' => __('Error page'), 'slow_query' => __('Slow query'),
            'script' => __('Script error'), 'crash' => __('Crash'),
        ];
    }

    /** @return array<string, string> the statuses in plain words, by key */
    public static function statusLabels(): array
    {
        // A key of its own for "open": the product's shared "Open" is masculine in Arabic (an open contract), and an issue
        // (مشكلة) is feminine, as the words for resolved and ignored already are.
        return [self::STATUS_OPEN => __('Open issue'), self::STATUS_RESOLVED => __('Resolved'), self::STATUS_IGNORED => __('Ignored')];
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
