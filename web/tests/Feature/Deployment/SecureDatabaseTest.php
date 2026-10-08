<?php

use Illuminate\Support\Facades\DB;

function onPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

/** Tables of the current schema that row-level security does not cover. */
function tablesWithoutRls(): array
{
    return collect(DB::select(
        "select c.relname as name from pg_class c join pg_namespace n on n.oid = c.relnamespace
         where n.nspname = current_schema() and c.relkind in ('r', 'p') and not c.relrowsecurity",
    ))->pluck('name')->all();
}

/** Supabase creates these two roles in every project; the test database may not have them. */
function createApiRoles(): void
{
    foreach (['anon', 'authenticated'] as $role) {
        DB::statement("do \$\$ begin if not exists (select 1 from pg_roles where rolname = '{$role}') then create role {$role} nologin; end if; end \$\$");
    }
}

describe('on PostgreSQL', function () {
    it('covers every table with row-level security', function () {
        expect(tablesWithoutRls())->not->toBe([]); // the migrations create tables, none has RLS yet

        $this->artisan('qistas:secure-database')->assertSuccessful();

        expect(tablesWithoutRls())->toBe([]);
    })->skip(fn () => ! onPostgres(), 'PostgreSQL only');

    it('takes Supabase’s API roles’ privileges away, now and for tables created later', function () {
        createApiRoles();
        DB::statement('grant select on customers to anon');
        DB::statement('grant select on customers to authenticated');
        // What Supabase does for every project: tables created from now on are readable by its API roles.
        DB::statement('alter default privileges in schema public grant select on tables to anon, authenticated');

        $this->artisan('qistas:secure-database')->assertSuccessful();

        DB::statement('create table created_later (id integer)');

        $allowed = fn (string $role, string $table): bool => (bool) DB::scalar('select has_table_privilege(?, ?, ?)', [$role, $table, 'select']);
        expect($allowed('anon', 'customers'))->toBeFalse()
            ->and($allowed('authenticated', 'customers'))->toBeFalse()
            ->and($allowed('anon', 'created_later'))->toBeFalse()
            ->and($allowed('authenticated', 'created_later'))->toBeFalse();
    })->skip(fn () => ! onPostgres(), 'PostgreSQL only');

    it('leaves the application itself able to read and write its tables', function () {
        $this->artisan('qistas:secure-database')->assertSuccessful();

        // The connection's role owns the tables, and an owner is not subject to row-level security.
        $tenant = workspaceOn();

        expect(DB::table('tenants')->where('id', $tenant->id)->exists())->toBeTrue();
    })->skip(fn () => ! onPostgres(), 'PostgreSQL only');

    it('is safe to run twice', function () {
        $this->artisan('qistas:secure-database')->assertSuccessful();
        $this->artisan('qistas:secure-database')->assertSuccessful()->expectsOutputToContain('0 newly secured');
    })->skip(fn () => ! onPostgres(), 'PostgreSQL only');

    it('is part of the set-up command', function () {
        $this->artisan('qistas:setup')->assertSuccessful();

        expect(tablesWithoutRls())->toBe([]);
    })->skip(fn () => ! onPostgres(), 'PostgreSQL only');
});

it('does nothing, and says so, on a database without row-level security', function () {
    $this->artisan('qistas:secure-database')->assertSuccessful()->expectsOutputToContain('nothing to do');
})->skip(fn () => onPostgres(), 'not PostgreSQL');
