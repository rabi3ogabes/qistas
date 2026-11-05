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
 *   - The caps hold when workers run at the same moment. The counters are read first only as the cheap way out; the hour's
 *     counter is then taken with one atomic step (the count RateLimiter::hit returns), and an issue that finds itself past
 *     the cap sends nothing. A worker that loses that race wastes one place in the hour (bounded by the number of workers) and
 *     leaves its issue for the digest. The day's counter is written only after a mail has gone, so a dead mail server cannot use
 *     the day up and leave the first real alert after it recovers waiting until tomorrow; workers racing at the edge of the
 *     day's cap are still held by the hour's, so it can be passed by at most the hour's cap less one.
 *   - Every attempt takes its place in the hour whether or not the mail server answers, so a server that is down is tried 3
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

            // The count so far, and whether the issue is still one to tell about: staff may have resolved or ignored it, or
            // another worker told about it, since the event was fired with the copy this method was given. Read again, now
            // that it goes out.
            $current = ErrorIssue::query()->find($issue->getKey());

            if ($current === null || ! self::wouldAlert($current)) {
                return false;
            }

            // The pre-check above saw the counters before any of the workers racing with this one wrote to them. The place in
            // the hour is taken in one atomic step, and the count it gives back says whether there was one.
            if (RateLimiter::hit(self::HOUR_KEY, self::HOUR_SECONDS) > self::HOUR_LIMIT) {
                return false;
            }

            if ($this->deliver($recipients, static fn (): ErrorAlertMail => new ErrorAlertMail($current)) === 0) {
                return false;
            }

            RateLimiter::hit(self::DAY_KEY, self::DAY_SECONDS);
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
     * 24 hours, or is growing. Only open issues are listed: one that staff resolved or ignored is dealt with. An issue is in
     * one list only (came back, then new, then growing), so it is not told twice.
     *
     * What it did is the outcome: Sent when the mail went to somebody; NothingToSend on a quiet day, or when nobody can be
     * told; Failed when there was something to say, people to say it to, and no mail could be sent. A mail that cannot be sent
     * is swallowed (never reported), and the command turns Failed into a failing exit, so the digest of a day does not vanish
     * the way a quiet day does.
     *
     * Known limit: "growing" is judged on the day so far, against the seven days before it (ErrorTrend::growing). An issue
     * that surged yesterday and is quiet so far today is not listed, and the early hours of a day show less than its end will.
     */
    public function digest(CarbonInterface $now): DigestOutcome
    {
        $since = CarbonImmutable::instance($now)->setTimezone(self::timezone())->subHours(self::DIGEST_HOURS);

        $cameBack = $this->ids($this->open()->where('reopened_at', '>=', $since), []);
        $new = $this->ids($this->open()->where('first_seen_at', '>=', $since), $cameBack);
        $growing = $this->growing($since, [...$cameBack, ...$new]);

        if ($cameBack === [] && $new === [] && $growing === []) {
            return DigestOutcome::NothingToSend;
        }

        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            return DigestOutcome::NothingToSend;
        }

        [$new, $moreNew] = $this->shown($new);
        [$cameBack, $moreBack] = $this->shown($cameBack);
        [$growing, $moreGrowing] = $this->shown($growing);
        $more = ['new' => $moreNew, 'cameBack' => $moreBack, 'growing' => $moreGrowing];

        $sent = $this->deliver($recipients, static fn (): ErrorDigestMail => new ErrorDigestMail($new, $cameBack, $growing, $more));

        return $sent > 0 ? DigestOutcome::Sent : DigestOutcome::Failed;
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
     * The issues that count for the digest at all: the open ones. A resolved or ignored issue is dealt with, whatever else is
     * true of it.
     *
     * @return Builder<ErrorIssue>
     */
    private function open(): Builder
    {
        return ErrorIssue::query()->where('status', ErrorIssue::STATUS_OPEN);
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
            $this->open()->where('last_seen_at', '>=', $since)->where('occurrences', '>=', ErrorTrend::GROWING_MIN),
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
