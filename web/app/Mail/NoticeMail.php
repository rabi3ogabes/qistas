<?php

namespace App\Mail;

use App\Notifications\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A notice by e-mail (Sprint 3 Task 1b): the same words as the app's notice, already in the person's language. */
final class NoticeMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PushMessage $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notice', with: ['notice' => $this->notice, 'link' => url('/app')]);
    }
}
