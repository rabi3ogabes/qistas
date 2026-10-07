<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** The standard verification email, sent from the queue so sign-up never waits for the mail server. */
class VerifyEmailQueued extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
