<?php

namespace App\Mail;

use App\Errors\ErrorAlerts;
use App\Models\ErrorIssue;
use App\Support\Locale;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Str;

/**
 * The instant e-mail for a new server error (error center design, section 8): which part of the product it came from, its
 * title in plain words, how many times it has happened and a link to its page. Nothing else about the failure leaves the
 * database: no trace, no message, no route, no person. The title is already scrubbed, and it is only ever shown as text.
 *
 * Not queued, on purpose: ErrorAlerts sends it while the recorder is at work and catches a failure of the mail server right
 * there, where it can swallow it without reporting it.
 */
final class ErrorAlertMail extends Mailable
{
    /** The longest a title may be in the subject line; the e-mail itself shows it whole. */
    private const SUBJECT_TITLE = 90;

    public function __construct(public readonly ErrorIssue $issue) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('New error (:source): :title', [
            'source' => $this->source(),
            'title' => Str::limit(ErrorAlerts::oneLine($this->issue->title), self::SUBJECT_TITLE),
        ]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.error-alert',
            text: 'mail.error-alert-text',
            with: [
                'language' => str_replace('_', '-', app()->getLocale()),
                'dir' => Locale::direction(),
                'brand' => (string) config('qistas.app_name'),
                'source' => $this->source(),
                'title' => ErrorAlerts::oneLine($this->issue->title),
                'count' => number_format($this->issue->occurrences),
                'version' => $this->issue->first_version,
                'link' => ErrorAlerts::link($this->issue),
                'hourLimit' => ErrorAlerts::HOUR_LIMIT,
                'dayLimit' => ErrorAlerts::DAY_LIMIT,
            ],
        );
    }

    /** Sent by a machine: an out-of-office reply or a mailing-list robot must not answer it. */
    public function headers(): Headers
    {
        return new Headers(text: ['Auto-Submitted' => 'auto-generated']);
    }

    private function source(): string
    {
        return ErrorIssue::sourceLabels()[$this->issue->source] ?? $this->issue->source;
    }
}
