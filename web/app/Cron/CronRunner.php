<?php

namespace App\Cron;

use App\Models\CronRun;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * One slice of scheduled work, for a host that has no scheduler and no queue worker: whatever is due in the
 * scheduler, then the jobs waiting on the database queue, until the time cap. The caller (CronController) makes sure
 * two slices never overlap.
 *
 * The queue is drained on the `database` connection whatever the default is: that is where work that should wait for
 * a tick is put (`->onConnection('database')`), while the default connection may be `sync` on a host like Vercel.
 */
final class CronRunner
{
    public const QUEUE = 'database';

    /** Three quarters of PHP's memory limit in megabytes (a generous fixed budget when there is no limit). */
    public static function memoryBudgetMb(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return 2048;
        }

        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return max(128, intdiv(intdiv($bytes, 1024 * 1024) * 3, 4));
    }

    public function run(): CronRun
    {
        $run = CronRun::create(['started_at' => now(), 'outcome' => 'running']);
        $jobs = 0;

        Event::listen([JobProcessed::class, JobFailed::class], function () use (&$jobs): void {
            $jobs++;
        });

        try {
            Artisan::call('schedule:run');
            Artisan::call('queue:work', [
                'connection' => self::QUEUE,
                '--stop-when-empty' => true,
                '--max-time' => max(1, (int) config('qistas.cron.max_seconds')),
                '--tries' => 1,
                // The worker's own default stops after a job once the process passes 128 MB, which a busy request can;
                // let it use most of what PHP really allows instead.
                '--memory' => self::memoryBudgetMb(),
            ]);

            $outcome = 'ok';
            $error = null;
        } catch (Throwable $e) {
            report($e);
            $outcome = 'failed';
            // What went wrong, for the person running the site: the class and message, never a trace or a payload.
            $error = Str::limit($e::class.': '.$e->getMessage(), 500);
        }

        $run->forceFill(['finished_at' => now(), 'outcome' => $outcome, 'jobs' => $jobs, 'error' => $error])->save();

        return $run;
    }
}
