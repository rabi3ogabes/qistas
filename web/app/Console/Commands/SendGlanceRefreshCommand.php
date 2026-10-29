<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\Alerts\GlanceRefresh;
use Illuminate\Console\Command;

/**
 * The home-screen widget's morning refresh (daily-habit wave A, H3). Run every five minutes by the scheduler; each person's
 * phones are woken once a day, at their chosen morning-summary time on their workspace's clock, so running it again is harmless.
 */
final class SendGlanceRefreshCommand extends Command
{
    protected $signature = 'qistas:send-glance-refresh';

    protected $description = 'Quietly refresh the home-screen widget on the phones of everyone whose time has come';

    public function handle(GlanceRefresh $refresh): int
    {
        $told = 0;
        // Demo accounts are throwaway: nobody to tell.
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            $told += $refresh->run($tenant, now());
        }
        $this->components->info("Refreshed the phones of {$told} person(s).");

        return self::SUCCESS;
    }
}
