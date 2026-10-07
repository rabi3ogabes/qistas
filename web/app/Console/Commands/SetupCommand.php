<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the database schema up to date. The container runs this on every start, and a host may start several
 * copies at once, so on PostgreSQL it holds an advisory lock: one copy migrates, the others wait and then find
 * nothing left to do. (Behind a transaction pooler the lock is only a hint, which is why the container also
 * retries.) Safe to run any number of times.
 */
final class SetupCommand extends Command
{
    /** An arbitrary, fixed number that identifies "Qistas is migrating" among the database's advisory locks. */
    private const LOCK = 727_410_001;

    protected $signature = 'qistas:setup {--demo : Also create the demo workspace (local use only, never in production)}';

    protected $description = 'Bring the database schema up to date; safe to run from several copies at once';

    public function handle(): int
    {
        $connection = DB::connection();
        $locking = $connection->getDriverName() === 'pgsql';

        if ($locking) {
            $connection->select('select pg_advisory_lock(?)', [self::LOCK]);
        }

        try {
            $this->call('migrate', ['--force' => true]);

            if ($this->option('demo')) {
                $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            }
        } finally {
            if ($locking) {
                $connection->select('select pg_advisory_unlock(?)', [self::LOCK]);
            }
        }

        return self::SUCCESS;
    }
}
