<?php

use Illuminate\Support\Facades\DB;

it('says the database is ready, in plain words', function () {
    $this->artisan('qistas:db-check')->assertSuccessful()->expectsOutputToContain('The database is ready to use.');
});

it('explains a refused connection without showing the password', function () {
    $original = config('database.default');
    config([
        'database.default' => 'broken',
        'database.connections.broken' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'x', 'username' => 'someone',
            'password' => 'hunter2-super-secret', 'sslmode' => 'disable', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public',
        ],
    ]);
    DB::purge('broken');

    try {
        $this->artisan('qistas:db-check')->assertFailed()
            ->expectsOutputToContain('Could not connect to the database.')
            ->doesntExpectOutputToContain('hunter2-super-secret');
    } finally {
        // The test's own transaction is rolled back on the default connection afterwards: put it back.
        config(['database.default' => $original]);
    }
})->skip(! extension_loaded('pdo_pgsql'), 'needs pdo_pgsql');

it('reads the server, the user and the schema of a PostgreSQL database', function () {
    $this->artisan('qistas:db-check')->assertSuccessful()
        ->expectsOutputToContain('Connected to PostgreSQL')
        ->expectsOutputToContain('signed in as')
        ->expectsOutputToContain('migrations applied');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL only');
