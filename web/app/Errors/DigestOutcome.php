<?php

declare(strict_types=1);

namespace App\Errors;

/**
 * What the daily digest did (ErrorAlerts::digest): it went to somebody; there was nothing to say, or nobody to say it to; or
 * there was something to say, and there were people to tell, and no mail could be sent. The last one is not the same as the
 * second: the command fails on it, so a digest that never arrived does not look like a quiet day.
 */
enum DigestOutcome: string
{
    case Sent = 'sent';
    case NothingToSend = 'nothing';
    case Failed = 'failed';
}
