<?php

namespace App\Console\Commands;

use App\Billing\BillingNotices;
use Illuminate\Console\Command;

/** Tells owners before a plan they pay for ends, and once it has ended (Sprint 3 Task 1b). Safe to run any number of times. */
final class BillingNoticesCommand extends Command
{
    protected $signature = 'qistas:billing-notices';

    protected $description = 'Tell owners before a paid plan ends without renewing, and once it has ended';

    public function handle(BillingNotices $notices): int
    {
        $notices->run();

        return self::SUCCESS;
    }
}
