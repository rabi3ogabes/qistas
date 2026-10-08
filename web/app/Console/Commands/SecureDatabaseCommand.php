<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Makes sure Supabase's public data API cannot reach this application's tables.
 *
 * Supabase exposes every table of the `public` schema to its API roles (`anon`, `authenticated`) unless row-level
 * security says otherwise, and anyone can find a project's public API key. This app never uses that API (it talks to
 * the database directly, as the table owner, which row-level security does not apply to), so every table gets
 * row-level security with no policy (nothing is visible through the API) and the API roles lose their privileges,
 * including on tables created later. Safe to run any number of times; it does nothing on other databases.
 */
final class SecureDatabaseCommand extends Command
{
    /** Roles Supabase's data API connects as. */
    private const API_ROLES = ['anon', 'authenticated'];

    protected $signature = 'qistas:secure-database';

    protected $description = 'Turn on row-level security for every table and cut off Supabase API roles';

    public function handle(): int
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->components->info('Row-level security is a PostgreSQL feature: nothing to do on '.$connection->getDriverName().'.');

            return self::SUCCESS;
        }

        $grammar = $connection->getQueryGrammar();

        $tables = collect($connection->select(
            "select c.relname as name from pg_class c join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = current_schema() and c.relkind in ('r', 'p') and not c.relrowsecurity",
        ))->pluck('name');

        foreach ($tables as $table) {
            $connection->statement('alter table '.$grammar->wrapTable($table).' enable row level security');
        }

        $roles = collect($connection->select('select rolname from pg_roles where rolname in (?, ?)', self::API_ROLES))->pluck('rolname');

        foreach ($roles as $role) {
            $who = $grammar->wrap($role);

            foreach (['tables', 'sequences', 'functions'] as $kind) {
                $connection->statement("revoke all on all {$kind} in schema public from {$who}");
                $connection->statement("alter default privileges in schema public revoke all on {$kind} from {$who}");
            }
        }

        $this->components->info(sprintf(
            'Row-level security is on for every table (%d newly secured); %s.',
            $tables->count(),
            $roles->isEmpty() ? 'no Supabase API roles exist here' : 'the Supabase API roles ('.$roles->implode(', ').') have no access',
        ));

        return self::SUCCESS;
    }
}
