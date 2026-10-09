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
