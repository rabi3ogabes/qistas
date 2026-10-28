<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\Alerts\DayCloseReminders;
use Illuminate\Console\Command;

/**
 * The evening reminder to close the day (daily-habit wave A, H4). Run every five minutes by the scheduler; each person is
 * reminded once a day, from their workspace's closing time and for two hours after it, on that workspace's clock, so
 * running it again is harmless.
 */
final class SendDayCloseRemindersCommand extends Command
{
    protected $signature = 'qistas:send-day-close-reminders';

    protected $description = 'Remind everyone who may close the day, in each workspace whose closing time has come';

    public function handle(DayCloseReminders $reminders): int
    {
        $told = 0;
        // Demo accounts are throwaway: nobody to tell.
        foreach (Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor() as $tenant) {
            $told += $reminders->run($tenant, now());
        }
        $this->components->info("Reminded {$told} person(s).");

        return self::SUCCESS;
    }
}
