<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings the database schema up to date and secures it (see SecureDatabaseCommand). The container runs this on every
 * start, and a host may start several copies at once, so on PostgreSQL it holds an advisory lock for the whole
 * transaction: one copy migrates, the others wait and then find nothing left to do. Safe to run any number of times.
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

        $setUp = function (): void {
            $this->call('migrate', ['--force' => true]);
            // A feature added in this release gets its platform switch (dark) before anyone can use it.
            $this->call('qistas:sync-features');
            $this->call('qistas:secure-database');

            if ($this->option('demo')) {
                $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            }
        };

        if ($connection->getDriverName() !== 'pgsql') {
            $setUp();

            return self::SUCCESS;
        }

        // One transaction, one transaction-scoped lock: a transaction pooler (Supabase, port 6543) may hand every
        // statement to a different connection, and a session-level lock would then be left behind for good. This
        // lock ends with the transaction, and a failed migration leaves nothing half-done.
        $connection->transaction(function () use ($connection, $setUp): void {
            $connection->select('select pg_advisory_xact_lock(?)', [self::LOCK]);
            $setUp();
        });

        return self::SUCCESS;
    }
}
