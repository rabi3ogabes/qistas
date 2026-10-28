<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\Alerts\DayCloseReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The evening reminder to close the day (daily-habit wave A, H4). Run every five minutes by the scheduler; each person is
 * reminded once a day, from their workspace's closing time and for up to two hours after it (never past midnight), on
 * that workspace's clock, so running it again is harmless. A workspace that fails is logged and left for the next run,
 * and never keeps the workspaces after it from being reminded.
 */
final class SendDayCloseRemindersCommand extends Command
{
    protected $signature = 'qistas:send-day-close-reminders';

    protected $description = 'Remind everyone who may close the day, in each workspace whose closing time has come';

    public function handle(DayCloseReminders $reminders): int
    {
        $told = 0;
        $failed = 0;
        // Demo accounts are throwaway: nobody to tell.
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            try {
                $told += $reminders->run($tenant, now());
            } catch (Throwable $e) {
                // Which workspace and what kind of failure; never a message, which may carry a name.
                Log::warning('day close reminders failed', ['tenant' => $tenant->id, 'error' => $e::class]);
                $failed++;
            }
        }
        $this->components->info("Reminded {$told} person(s).");
        if ($failed > 0) {
            $this->components->warn("{$failed} workspace(s) failed; see the log.");
        }

        return self::SUCCESS;
    }
}
