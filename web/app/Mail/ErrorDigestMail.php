<?php

namespace App\Mail;

use App\Errors\ErrorAlerts;
use App\Errors\ErrorRecorder;
use App\Errors\ErrorTrend;
use App\Models\ErrorIssue;
use App\Support\Locale;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * The daily digest of the error center (design section 8): what is new in the last 24 hours, what came back, and what is
 * growing, each issue with its source, its title in plain words, how often it has happened and a link to its page. Like the
 * instant e-mail it carries no trace, message, route or person, and it is not queued (see ErrorAlertMail).
 *
 * Each list holds at most ErrorAlerts::LIST_LIMIT issues, the most often seen first; `$more` says how many more there were,
 * by list (`new`, `cameBack`, `growing`), and the e-mail links to the whole list of issues.
 *
 * Some of what an issue is called was written by a stranger: anybody can post a script error or a crash, and a visitor picks
 * the address of a page that is not found. An issue reported by a browser or a phone is therefore shown by its kind ("Script
 * error", "Crash") and who reported it, never by its title, and a missing page keeps its title but is marked as one. A title
 * in an e-mail to the owner is a sentence a stranger could have put there ("Your account is locked, open ...").
 */
final class ErrorDigestMail extends Mailable
{
    /**
     * @param  list<ErrorIssue>  $new  first seen in the last 24 hours
     * @param  list<ErrorIssue>  $cameBack  resolved, then seen again in the last 24 hours
     * @param  list<ErrorIssue>  $growing  seen today at least 3 times and at least double the daily average of the week before
     * @param  array{new?: int, cameBack?: int, growing?: int}  $more  issues beyond the lists' limit, by list
     */
    public function __construct(
        public readonly array $new,
        public readonly array $cameBack,
        public readonly array $growing,
        public readonly array $more = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Error summary of the last 24 hours'));
    }

    /** Sent by a machine: an out-of-office reply or a mailing-list robot must not answer it. */
    public function headers(): Headers
    {
        return new Headers(text: ['Auto-Submitted' => 'auto-generated']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.error-digest',
            text: 'mail.error-digest-text',
            with: [
                'language' => str_replace('_', '-', app()->getLocale()),
                'dir' => Locale::direction(),
                'brand' => (string) config('qistas.app_name'),
                'sections' => $this->sections(),
                'listLink' => ErrorAlerts::listLink(),
            ],
        );
    }

    /**
     * The parts that have something in them, in the order they are read, each ready to print.
     *
     * @return list<array{key: string, heading: string, hint: string, issues: list<array{source: string, note: string|null, title: string, count: string, link: string}>, more: int}>
     */
    private function sections(): array
    {
        $sources = ErrorIssue::sourceLabels();
        $kinds = ErrorIssue::kindLabels();
        $growingHint = __('Today at least :times times, and at least double the daily average of the :days days before.', [
            'times' => ErrorTrend::GROWING_MIN,
            'days' => ErrorTrend::WINDOW,
        ]);
        $parts = [
            ['new', $this->new, __('New in the last 24 hours'), __('Seen for the first time.')],
            ['cameBack', $this->cameBack, __('Came back in the last 24 hours'), __('Resolved, then seen again.')],
            ['growing', $this->growing, __('Growing'), $growingHint],
        ];
        $sections = [];

        foreach ($parts as [$key, $issues, $heading, $hint]) {
            if ($issues === []) {
                continue;
            }

            $sections[] = [
                'key' => $key,
                'heading' => $heading,
                'hint' => $hint,
                'issues' => array_map(static fn (ErrorIssue $issue): array => self::row($issue, $sources, $kinds), $issues),
                'more' => max(0, (int) ($this->more[$key] ?? 0)),
            ];
        }

        return $sections;
    }

    /**
     * One issue as the e-mail prints it. Reported by a browser or a phone: the kind of report and who sent it, no title. A page
     * that was not found: its title, marked. Anything else: its title.
     *
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $kinds
     * @return array{source: string, note: string|null, title: string, count: string, link: string}
     */
    private static function row(ErrorIssue $issue, array $sources, array $kinds): array
    {
        $reported = ErrorRecorder::clientReported($issue->source, $issue->kind);

        return [
            'source' => $sources[$issue->source] ?? $issue->source,
            'note' => match (true) {
                $reported => $issue->source === 'app' || $issue->kind === 'crash' ? __('Reported by a phone') : __('Reported by a browser'),
                $issue->kind === 'http' && $issue->class === 'http.404' => __('Page not found'),
                default => null,
            },
            'title' => $reported ? ($kinds[$issue->kind] ?? $issue->kind) : ErrorAlerts::oneLine($issue->title),
            'count' => number_format($issue->occurrences),
            'link' => ErrorAlerts::link($issue),
        ];
    }
}
