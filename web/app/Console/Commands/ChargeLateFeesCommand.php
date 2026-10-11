<?php

namespace App\Console\Commands;

use App\Actions\ChargeLateFees;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Charges every workspace's late fees (Win Plan K4). The scheduler runs it every five minutes so that each workspace
 * is charged soon after its own midnight; a workspace done today is skipped until its next day. Charging is idempotent
 * and catches up days missed, so a run that fails or is skipped costs nothing but time.
 */
final class ChargeLateFeesCommand extends Command
{
    protected $signature = 'qistas:charge-late-fees';

    protected $description = 'Charge the late fees each workspace owes up to its own today';

    public function handle(ChargeLateFees $charge): int
    {
        $fees = 0;
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            $done = 'late-fees:'.$tenant->id.':'.$tenant->localTime(now())->format('Y-m-d');
            if (Cache::has($done)) {
                continue;
            }

            $fees += $charge->handle($tenant);
            Cache::put($done, true, now()->addDays(2));
        }
        $this->components->info("Charged {$fees} late fee(s).");

        return self::SUCCESS;
    }
}
