<?php

namespace App\Site;

use App\Models\CronRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * How Qistas is doing right now, for the public status page (Sprint 2, decision D10): whether the database answers and
 * when scheduled work last ran. Only states and times leave this class: never an error, a count or a setting.
 */
final class ServiceStatus
{
    /** Scheduled work runs every five minutes; a run older than this is late (as in the admin overview). */
    public const LATE_AFTER_MINUTES = 15;

    /**
     * @return array{database: bool, scheduler: 'running'|'delayed'|'not_started', last_run: Carbon|null, ok: bool}
     */
    public static function check(): array
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (Throwable) {
            $database = false;
        }

        $run = $database && (string) config('qistas.cron.secret') !== ''
            ? CronRun::query()->orderByDesc('started_at')->first(['started_at', 'outcome'])
            : null;
        $scheduler = match (true) {
            $run === null => 'not_started',
            $run->outcome === 'failed' || $run->started_at->lt(now()->subMinutes(self::LATE_AFTER_MINUTES)) => 'delayed',
            default => 'running',
        };

        return ['database' => $database, 'scheduler' => $scheduler, 'last_run' => $run?->started_at, 'ok' => $database && $scheduler !== 'delayed'];
    }
}
