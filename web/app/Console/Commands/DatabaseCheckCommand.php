<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Can this app use that database?" in plain words, for the person connecting Supabase. Prints what it found and
 * what to do about a problem. Never prints the password, the user's secrets or the connection string.
 */
final class DatabaseCheckCommand extends Command
{
    protected $signature = 'qistas:db-check';

    protected $description = 'Check the database connection and say what is wrong, if anything';

    public function handle(Migrator $migrator): int
    {
        $name = config('database.default');
        $config = config("database.connections.{$name}");
        $driver = $config['driver'] ?? $name;

        $started = microtime(true);

        try {
            $connection = DB::connection();
            // What the connection really uses: a DB_URL has been taken apart into host, port, user and so on.
            $config = $connection->getConfig() ?: $config;
            $connection->getPdo();
        } catch (Throwable $e) {
            $this->components->error('Could not connect to the database.');
            $this->line('  '.$this->advice($e->getMessage(), $config));

            return self::FAILURE;
        }

        $milliseconds = (int) round((microtime(true) - $started) * 1000);
        $this->components->info(sprintf('Connected to %s in %d ms.', $this->describe($connection, $driver), $milliseconds));

        if ($driver === 'pgsql') {
            $port = (string) ($config['port'] ?? '');
            $host = (string) ($config['host'] ?? '');
            $pooled = $port === '6543' || str_contains($host, 'pooler');
            $this->line(sprintf('  host %s, port %s%s', $host, $port, $pooled ? ' (connection pooler: right for Vercel)' : ''));

            try {
                $ssl = $connection->selectOne('select ssl from pg_stat_ssl where pid = pg_backend_pid()');
                $this->line('  encrypted connection: '.(($ssl->ssl ?? false) ? 'yes' : 'NO (set DB_SSLMODE=require)'));
            } catch (Throwable) {
                $this->line('  encrypted connection: could not tell');
            }

            $role = $connection->selectOne('select current_user as name, (select rolbypassrls or rolsuper from pg_roles where rolname = current_user) as bypass');
            $this->line(sprintf('  signed in as %s%s', $role->name, $role->bypass ? '' : ' (this role is subject to row-level security: use the default postgres user)'));
        }

        try {
            $tables = count($connection->getSchemaBuilder()->getTables($connection->getSchemaBuilder()->getCurrentSchemaName() ?? null));
            $ran = $connection->getSchemaBuilder()->hasTable('migrations') ? $connection->table('migrations')->count() : 0;
            $total = count($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]));
            $this->line(sprintf('  %d tables, %d of %d migrations applied%s', $tables, $ran, $total, $ran < $total ? ' (the app sets up the rest when it starts)' : ''));
        } catch (Throwable) {
            $this->line('  could not read the table list');
        }

        $this->components->info('The database is ready to use.');

        return self::SUCCESS;
    }

    private function describe(Connection $connection, string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'PostgreSQL '.explode(' ', (string) $connection->selectOne('show server_version')->server_version)[0],
            'sqlite' => 'SQLite (a local file: fine for trying things out, not for a live site)',
            default => ucfirst($driver),
        };
    }

    /** @param  array<string, mixed>  $config */
    private function advice(string $message, array $config): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'password authentication failed') => 'The password is wrong. Use the database password you chose when you created the project (Project settings → Database → Reset database password if you forgot it).',
            str_contains($lower, 'tenant or user not found') => 'The user name is wrong. With the connection pooler it looks like postgres.<project-ref>; copy the whole string from Supabase → Connect.',
            str_contains($lower, 'network is unreachable'), str_contains($lower, 'no route to host') => 'This computer or host cannot reach the database over IPv6. Use the "Transaction pooler" connection string (host ends in pooler.supabase.com), which works over IPv4.',
            str_contains($lower, 'could not translate host name'), str_contains($lower, 'name or service not known'), str_contains($lower, 'getaddrinfo') => 'The host name was not found. Check the connection string for typos.',
            str_contains($lower, 'timeout'), str_contains($lower, 'timed out') => 'The connection timed out. Check your internet connection and that the project is not paused (Supabase pauses idle free projects).',
            str_contains($lower, 'connection refused') => 'Nothing is listening at '.($config['host'] ?? '?').':'.($config['port'] ?? '?').'. Check the host and port.',
            str_contains($lower, 'ssl') => 'The secure connection failed. The Supabase string needs DB_SSLMODE=require.',
            default => 'The database refused the connection. The first line of the error was: '.trim(strtok($this->scrub($message, $config), "\n")),
        };
    }

    /**
     * Remove anything secret from a message before it is shown.
     *
     * @param  array<string, mixed>  $config
     */
    private function scrub(string $message, array $config): string
    {
        foreach (['password', 'username', 'url'] as $key) {
            $secret = (string) ($config[$key] ?? '');
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
