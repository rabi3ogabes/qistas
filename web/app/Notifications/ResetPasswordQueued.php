<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The standard reset email, sent from the queue. The request that asked for it does the same amount of
 * work whether or not the address belongs to an account, and a slow mail server never slows the page.
 */
class ResetPasswordQueued extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
