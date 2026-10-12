<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The e-mail a super admin sends to themselves from /admin/mail to see that the saved SMTP server works. */
final class SettingsCheck extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Qistas can send e-mail'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.settings-check');
    }
}
