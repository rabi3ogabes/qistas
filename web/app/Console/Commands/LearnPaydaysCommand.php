<?php

namespace App\Console\Commands;

use App\Domain\PayPattern\RecomputePayPattern;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every customer's usual payday, worked out again (daily-habit wave A, H6). Run once a night by the scheduler for the
 * workspaces that have payday learning switched on (asked when it runs, not when it was scheduled); a workspace without it
 * is left exactly as it is. Payments and voids keep a pattern current during the day, so this catches what they could not
 * (a payment that fell out of the 18-month window, a customer deleted since) and repairs anything missed, and running it
 * again changes nothing but when each pattern was worked out. A workspace that fails is logged and left for the next run,
 * and never keeps the workspaces after it from being worked out.
 */
final class LearnPaydaysCommand extends Command
{
    protected $signature = 'qistas:learn-paydays';

    protected $description = 'Work out again the day of the month each customer pays on, in every workspace that has payday learning on';

    public function handle(RecomputePayPattern $recompute): int
    {
        $stored = 0;
        $failed = 0;
        // Demo accounts are throwaway: nobody to learn about.
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            try {
                $stored += $recompute->forWorkspace($tenant);
            } catch (Throwable $e) {
                // Which workspace and what kind of failure; never a message, which may carry a name.
                Log::warning('payday learning failed', ['tenant' => $tenant->id, 'error' => $e::class]);
                $failed++;
            }
        }
        $this->components->info("Stored {$stored} pattern(s).");
        if ($failed > 0) {
            $this->components->warn("{$failed} workspace(s) failed; see the log.");
        }

        return self::SUCCESS;
    }
}
