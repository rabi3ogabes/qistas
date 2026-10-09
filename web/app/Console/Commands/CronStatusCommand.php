<?php

namespace App\Console\Commands;

use App\Models\CronRun;
use Illuminate\Console\Command;

/** Says whether scheduled work is running: the last call of the cron endpoint, how long ago, and the last failures. */
final class CronStatusCommand extends Command
{
    protected $signature = 'qistas:cron-status';

    protected $description = 'Show when scheduled work last ran and whether it has been failing';

    public function handle(): int
    {
        if ((string) config('qistas.cron.secret') === '') {
            $this->components->warn('CRON_SECRET is not set, so the cron endpoint is closed (503). Set it to switch scheduled work on.');
        }

        $last = CronRun::query()->orderByDesc('started_at')->first();

        if ($last === null) {
            $this->components->info('No run yet. The first call of /internal/cron will show up here.');

            return self::SUCCESS;
        }

        $minutes = (int) $last->started_at->diffInMinutes(now(), true);
        $this->components->twoColumnDetail('Last run', $last->started_at->utc()->format('Y-m-d H:i:s').' UTC ('.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').' ago)');
        $this->components->twoColumnDetail('Outcome', $last->outcome);
        $this->components->twoColumnDetail('Jobs done', (string) $last->jobs);

        $failures = CronRun::query()->where('outcome', 'failed')->orderByDesc('started_at')->limit(5)->get();

        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->components->warn('Last failures');
            foreach ($failures as $failure) {
                $this->components->twoColumnDetail($failure->started_at->utc()->format('Y-m-d H:i:s').' UTC', (string) $failure->error);
            }
        }

        return self::SUCCESS;
    }
}
