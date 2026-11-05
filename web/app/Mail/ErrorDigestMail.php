<?php

namespace App\Mail;

use App\Errors\ErrorAlerts;
use App\Models\ErrorIssue;
use App\Support\Locale;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily digest of the error center (design section 8): what is new in the last 24 hours, what came back, and what is
 * growing, each issue with its source, its title in plain words, how often it has happened and a link to its page. Like the
 * instant e-mail it carries no trace, message, route or person, and it is not queued (see ErrorAlertMail).
 *
 * Each list holds at most ErrorAlerts::LIST_LIMIT issues, the most often seen first; `$more` says how many more there were,
 * by list (`new`, `cameBack`, `growing`), and the e-mail links to the whole list of issues.
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
     * @return list<array{key: string, heading: string, hint: string, issues: list<array{source: string, title: string, count: string, link: string}>, more: int}>
     */
    private function sections(): array
    {
        $sources = ErrorIssue::sourceLabels();
        $parts = [
            ['new', $this->new, __('New in the last 24 hours'), __('Seen for the first time.')],
            ['cameBack', $this->cameBack, __('Came back in the last 24 hours'), __('Resolved, then seen again.')],
            ['growing', $this->growing, __('Growing'), __('Today at least 3 times, and at least double the daily average of the 7 days before.')],
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
                'issues' => array_map(static fn (ErrorIssue $issue): array => [
                    'source' => $sources[$issue->source] ?? $issue->source,
                    'title' => ErrorAlerts::oneLine($issue->title),
                    'count' => number_format($issue->occurrences),
                    'link' => ErrorAlerts::link($issue),
                ], $issues),
                'more' => max(0, (int) ($this->more[$key] ?? 0)),
            ];
        }

        return $sections;
    }
}
