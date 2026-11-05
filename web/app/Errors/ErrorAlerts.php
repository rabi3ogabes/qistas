<?php

declare(strict_types=1);

namespace App\Errors;

use App\Errors\Events\ErrorIssueCreated;
use App\Mail\ErrorAlertMail;
use App\Mail\ErrorDigestMail;
use App\Models\ErrorIssue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * How the error center tells the owner (design section 8): the instant e-mail for a brand-new server error, and the daily
 * digest of what is new, came back or is growing. Both go to the same people, every active (not suspended) super admin, each
 * in the language of their own account; an `admin` is not told.
 *
 * The instant e-mail is the listener of ErrorIssueCreated, which the recorder fires once per new issue, after the issue is
 * stored. It goes out only for a failure of ours that is verified (source site, api or database) and serious (an exception, or
 * an error page of 500 or more): never for an error page below 500, a slow query, or what a browser or a phone reports about
 * itself (unverifiable, so it can never be made to send mail). It is counted against two caps over all issues, 3 an hour and
 * 10 a day, through the rate limiter; an issue a cap holds back is not lost, it is in the next digest. `alerted_at` records
 * that it went.
 *
 * Rules that hold whatever happens:
 *   - instant() never throws. It runs while the recorder is at work, so it never reports what goes wrong (a report would
 *     re-enter the capture layer): a mail that cannot be sent, a database that does not answer, a cache that refuses a count
 *     are all swallowed, and the issue stays stored as the recorder left it. The mails are not queued, so the failure is
 *     caught here and not by a worker later.
 *   - An attempt takes its place in the caps whether or not the mail server answers, so a server that is down is tried 3
 *     times an hour, not once for every new issue of a flood.
 *   - Nothing is read for an issue that never alerts, and nobody is looked up until an alert is really going out.
 *   - The e-mails carry the source, the title (already scrubbed; the views escape it all the same), the count and a link,
 *     never a trace, a message, a route or a person. The link is built on the address in the settings (APP_URL), not on the
 *     host of the request that happened to fail, which a stranger could have written.
 */
final class ErrorAlerts
{
    /** The sources whose failures are ours and verified. */
    public const SOURCES = ['site', 'api', 'database'];

    /** An error page alerts from this status up. */
    public const MIN_STATUS = 500;

    public const HOUR_KEY = 'error-alerts-hour';

    public const HOUR_LIMIT = 3;

    public const HOUR_SECONDS = 3600;

    public const DAY_KEY = 'error-alerts-day';

    public const DAY_LIMIT = 10;

    public const DAY_SECONDS = 86400;

    /** The digest looks back this many hours. */
    public const DIGEST_HOURS = 24;

    /** Issues listed in each part of the digest; the rest are counted ("and 5 more") and are one click away. */
    public const LIST_LIMIT = 25;

    /** The listener of ErrorIssueCreated. */
    public function handle(ErrorIssueCreated $event): void
    {
        $this->instant($event->issue);
    }

    /**
     * Tells the super admins about a new issue when it is the kind that alerts and no cap is reached; returns whether the
     * mail went to anybody. Never throws.
     */
    public function instant(ErrorIssue $issue): bool
    {
        try {
            if (! self::wouldAlert($issue) || $this->capped()) {
                return false;
            }

            $recipients = $this->recipients();

            if ($recipients->isEmpty()) {
                return false;
            }

            // The count so far, and whether anyone has dealt with it in the meantime: read again, now that it goes out.
            $current = ErrorIssue::query()->find($issue->getKey());

            if ($current === null || ! self::wouldAlert($current)) {
                return false;
            }

            RateLimiter::hit(self::HOUR_KEY, self::HOUR_SECONDS);
            RateLimiter::hit(self::DAY_KEY, self::DAY_SECONDS);

            if ($this->deliver($recipients, static fn (): ErrorAlertMail => new ErrorAlertMail($current)) === 0) {
                return false;
            }

            ErrorIssue::query()->whereKey($current->getKey())->update(['alerted_at' => now()]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Does this issue earn an instant e-mail? An open issue not yet told about, from the website, the API or the database,
     * that is an exception or an error page of 500 or more (for an error page the class is `http.<status>`). Reads nothing.
     */
    public static function wouldAlert(ErrorIssue $issue): bool
    {
        if ($issue->status !== ErrorIssue::STATUS_OPEN || $issue->alerted_at !== null) {
            return false;
        }

        if (! in_array($issue->source, self::SOURCES, true) || ErrorRecorder::clientReported($issue->source, $issue->kind)) {
            return false;
        }

        return match ($issue->kind) {
            'exception' => true,
            'http' => preg_match('~^http\.(\d{3})$~D', (string) $issue->class, $found) === 1 && (int) $found[1] >= self::MIN_STATUS,
            default => false,
        };
    }

    /**
     * The daily digest: one e-mail to each recipient, only when something is new in the last 24 hours, came back in the last
     * 24 hours, or is growing; returns whether it went to anybody. An ignored issue is in none of the lists, and an issue is
     * in one list only (came back, then new, then growing), so it is not told twice. A mail that cannot be sent is swallowed.
     */
    public function digest(CarbonInterface $now): bool
    {
        $since = CarbonImmutable::instance($now)->setTimezone(self::timezone())->subHours(self::DIGEST_HOURS);

        $cameBack = $this->ids(
            $this->live()->where('status', ErrorIssue::STATUS_OPEN)->where('reopened_at', '>=', $since),
            [],
        );
        $new = $this->ids($this->live()->where('first_seen_at', '>=', $since), $cameBack);
        $growing = $this->growing($since, [...$cameBack, ...$new]);

        if ($cameBack === [] && $new === [] && $growing === []) {
            return false;
        }

        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return false;
        }

        [$new, $moreNew] = $this->shown($new);
        [$cameBack, $moreBack] = $this->shown($cameBack);
        [$growing, $moreGrowing] = $this->shown($growing);
        $more = ['new' => $moreNew, 'cameBack' => $moreBack, 'growing' => $moreGrowing];

        return $this->deliver($recipients, static fn (): ErrorDigestMail => new ErrorDigestMail($new, $cameBack, $growing, $more)) > 0;
    }

    /** A link to an issue's page in the admin console. */
    public static function link(ErrorIssue $issue): string
    {
        return self::address().route('admin.errors.show', $issue, false);
    }

    /** A link to the list of issues. */
    public static function listLink(): string
    {
        return self::address().route('admin.errors.index', [], false);
    }

    /** One line of text: a stored title never holds a line break, and nothing in a subject line may. */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** The address of the site as the settings give it, without a closing slash. */
    private static function address(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Is either cap reached? Both are read before either is touched. */
    private function capped(): bool
    {
        return RateLimiter::tooManyAttempts(self::HOUR_KEY, self::HOUR_LIMIT)
            || RateLimiter::tooManyAttempts(self::DAY_KEY, self::DAY_LIMIT);
    }

    /**
     * Every active super admin, looked up when a mail is really going out. An `admin` is not told, and neither is anybody
     * whose account is suspended.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        return User::query()
            ->where('platform_role', 'super_admin')
            ->where('status', '!=', 'suspended')
            ->orderBy('email')
            ->get(['id', 'email', 'locale']);
    }

    /**
     * Sends a fresh mail to each recipient, in their language, and counts how many went. A mail that cannot be sent is
     * swallowed on purpose and silently (never reported: see the class comment); the next recipient is still tried.
     *
     * @param  Collection<int, User>  $recipients
     * @param  Closure(): Mailable  $mail
     */
    private function deliver(Collection $recipients, Closure $mail): int
    {
        $sent = 0;

        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->locale(self::language($user))->send($mail());
                $sent++;
            } catch (Throwable) {
                // this person is not told by e-mail this time
            }
        }

        return $sent;
    }

    /** The language of a person's account when the product has it, the application's own otherwise. */
    private static function language(User $user): string
    {
        $locale = $user->locale;

        return in_array($locale, (array) config('qistas.locales'), true) ? $locale : (string) config('app.locale');
    }

    /**
     * The issues that count for the digest at all: everything not ignored.
     *
     * @return Builder<ErrorIssue>
     */
    private function live(): Builder
    {
        return ErrorIssue::query()->where('status', '!=', ErrorIssue::STATUS_IGNORED);
    }

    /**
     * The ids of a query's issues, the most often seen first, without those already in an earlier list.
     *
     * @param  Builder<ErrorIssue>  $query
     * @param  list<string>  $taken
     * @return list<string>
     */
    private function ids(Builder $query, array $taken): array
    {
        $ids = $query->orderByDesc('occurrences')->orderByDesc('last_seen_at')->orderBy('id')->pluck('id')->all();

        return array_values(array_diff($ids, $taken));
    }

    /**
     * The open issues seen in the window that are growing by the one rule (ErrorTrend::growing): today's count against the
     * seven days before it, so eight days are read at once. An issue seen fewer times than the rule asks for in a day cannot
     * be growing, so it is not even looked at.
     *
     * @param  list<string>  $taken
     * @return list<string>
     */
    private function growing(CarbonImmutable $since, array $taken): array
    {
        $candidates = $this->ids(
            $this->live()->where('status', ErrorIssue::STATUS_OPEN)
                ->where('last_seen_at', '>=', $since)->where('occurrences', '>=', ErrorTrend::GROWING_MIN),
            $taken,
        );

        if ($candidates === []) {
            return [];
        }

        $days = ErrorTrend::days($candidates, ErrorTrend::WINDOW + 1);

        return array_values(array_filter($candidates, static function (string $id) use ($days): bool {
            $counts = $days[$id] ?? [];

            return $counts !== []
                && ErrorTrend::growing($counts[ErrorTrend::WINDOW], array_slice($counts, 0, ErrorTrend::WINDOW));
        }));
    }

    /**
     * The first LIST_LIMIT of a list of ids as issues, in the order given, and how many more there were.
     *
     * @param  list<string>  $ids
     * @return array{0: list<ErrorIssue>, 1: int}
     */
    private function shown(array $ids): array
    {
        $keep = array_slice($ids, 0, self::LIST_LIMIT);
        $found = $keep === [] ? collect() : ErrorIssue::query()->whereIn('id', $keep)->get()->keyBy('id');
        $issues = [];

        foreach ($keep as $id) {
            $issue = $found->get($id);

            if ($issue instanceof ErrorIssue) {
                $issues[] = $issue;
            }
        }

        return [$issues, count($ids) - count($keep)];
    }

    private static function timezone(): string
    {
        $zone = config('app.timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
