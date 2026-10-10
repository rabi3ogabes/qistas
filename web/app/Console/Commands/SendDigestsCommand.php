<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\Alerts\DailyDigest;
use Illuminate\Console\Command;

/**
 * The morning summaries (Win Plan PP9). Run every five minutes by the scheduler; each person is told once a day, at their
 * chosen time on their workspace's clock, so running it again is harmless.
 */
final class SendDigestsCommand extends Command
{
    protected $signature = 'qistas:send-digests';

    protected $description = 'Send the morning summary to everyone whose time has come';

    public function handle(DailyDigest $digest): int
    {
        $told = 0;
        // Demo accounts are throwaway: nobody to tell.
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            $told += $digest->run($tenant, now());
        }
        $this->components->info("Told {$told} person(s).");

        return self::SUCCESS;
    }
}
