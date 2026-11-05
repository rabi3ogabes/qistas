<?php

namespace App\Console\Commands;

use App\Errors\ErrorAlerts;
use Illuminate\Console\Command;

/**
 * Sends the error center's daily digest to the active super admins (design section 8): what is new, came back or is growing in
 * the last 24 hours. On a day when none of that is so, or when nobody can be told, nothing is sent. Meant to run once a day, at
 * 07:00 on the application's clock; running it again sends the same digest again.
 */
final class ErrorsDigestCommand extends Command
{
    protected $signature = 'qistas:errors:digest';

    protected $description = 'E-mail the super admins the summary of the last 24 hours of errors, when there is something to say';

    public function handle(ErrorAlerts $alerts): int
    {
        $this->components->info($alerts->digest(now()) ? 'Digest sent.' : 'Nothing to send.');

        return self::SUCCESS;
    }
}
