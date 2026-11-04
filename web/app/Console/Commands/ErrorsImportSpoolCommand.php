<?php

namespace App\Console\Commands;

use App\Errors\ErrorRecorder;
use App\Errors\ErrorSpool;
use Illuminate\Console\Command;

/**
 * Records the error reports that waited in the spool (storage/logs/error-spool.ndjson) while the database was away, and
 * removes them from it. Scheduled every five minutes; with nothing waiting it does nothing. What the database still
 * refuses stays in the spool for the next run. With capture switched off (QISTAS_ERRORS=false) the spool is left alone.
 */
final class ErrorsImportSpoolCommand extends Command
{
    protected $signature = 'qistas:errors:import-spool';

    protected $description = 'Record the error reports kept in the spool while the database was away';

    public function handle(ErrorSpool $spool, ErrorRecorder $recorder): int
    {
        if (! ErrorRecorder::enabled()) {
            $this->components->info('Error capture is off (QISTAS_ERRORS=false): the spool was left as it is.');

            return self::SUCCESS;
        }

        $imported = $spool->import($recorder);

        // Imported: an issue opened, counted or reopened. Dropped: taken out without being recorded (over a flood cap, an
        // ignored issue, a line nobody can read or one the database kept refusing).
        $this->components->info("Imported {$imported}, dropped {$spool->dropped()}.");

        return self::SUCCESS;
    }
}
