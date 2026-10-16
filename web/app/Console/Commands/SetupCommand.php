<?php

namespace App\Console\Commands;

use App\Entitlements\Feature;
use App\Models\PlatformSetting;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Brings the database schema up to date and secures it (see SecureDatabaseCommand). The container runs this on every
 * start, and a host may start several copies at once, so on PostgreSQL it holds an advisory lock for the whole
 * transaction: one copy migrates, the others wait and then find nothing left to do. Safe to run any number of times.
 *
 * A copy starts in front of a waiting visitor, so it starts fast: once a release has set the database up it leaves its
 * fingerprint (its migrations, its features and the security rules' version), and every later start of the same
 * release reads it and does nothing more. A new release, or --force, does the whole set-up again.
 */
final class SetupCommand extends Command
{
    /** Where the last set-up's fingerprint is kept (platform_settings). */
    public const FINGERPRINT = 'setup.fingerprint';

    /** An arbitrary, fixed number that identifies "Qistas is migrating" among the database's advisory locks. */
    private const LOCK = 727_410_001;

    protected $signature = 'qistas:setup
        {--demo : Also create the demo workspace (local use only, never in production)}
        {--force : Do the whole set-up even when this release already did it}';

    protected $description = 'Bring the database schema up to date; safe to run from several copies at once';

    public function handle(): int
    {
        $fingerprint = $this->fingerprint();

        if (! $this->option('force') && ! $this->option('demo') && $this->alreadySetUp($fingerprint)) {
            $this->line('The database is already set up for this release.');

            return self::SUCCESS;
        }

        $connection = DB::connection();

        $setUp = function () use ($fingerprint): void {
            $this->call('migrate', ['--force' => true]);
            // A feature added in this release gets its platform switch (dark) before anyone can use it.
            $this->call('qistas:sync-features');
            $this->call('qistas:secure-database');

            if ($this->option('demo')) {
                $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            }

            PlatformSetting::query()->updateOrInsert(['key' => self::FINGERPRINT], ['value' => $fingerprint, 'updated_at' => now()]);
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

    /** What this release would set up: its migrations, its features and the version of the security rules. */
    private function fingerprint(): string
    {
        $migrations = array_map('basename', glob(database_path('migrations/*.php')) ?: []);
        sort($migrations);

        return hash('sha256', implode("\n", [
            implode(',', $migrations),
            implode(',', array_map(fn (Feature $feature) => $feature->value, Feature::cases())),
            SecureDatabaseCommand::VERSION,
        ]));
    }

    private function alreadySetUp(string $fingerprint): bool
    {
        try {
            return Schema::hasTable('platform_settings')
                && PlatformSetting::query()->whereKey(self::FINGERPRINT)->value('value') === $fingerprint;
        } catch (Throwable) {
            return false; // A database that cannot answer yet is set up the long way, which says what is wrong.
        }
    }
}
