<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\Alerts\InstalmentAlerts;
use Illuminate\Console\Command;

/**
 * The instalment alerts (Win Plan PP9): due today, and late again after each person's chosen interval. Run every five
 * minutes by the scheduler; each alert is logged under a unique key, so running it again is harmless.
 */
final class InstalmentAlertsCommand extends Command
{
    protected $signature = 'qistas:instalment-alerts';

    protected $description = 'Alert people to the instalments due today and the ones still late';

    public function handle(InstalmentAlerts $alerts): int
    {
        $pushes = 0;
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            $pushes += $alerts->run($tenant, now());
        }
        $this->components->info("Sent {$pushes} alert(s).");

        return self::SUCCESS;
    }
}
