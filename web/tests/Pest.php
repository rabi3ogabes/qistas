<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests hit the database (SQLite in memory, rolled back per test). Vite assets are not built in
| the test run, so views render without the manifest.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/** Run $callback with $tenant as the active workspace (tenant-owned models need one). */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(CurrentTenant::class)->use($tenant, $callback);
}

/** The password every test account is created with; it satisfies the production password rules. */
const TEST_PASSWORD = 'S3cure!Passw0rd';

/**
 * A signed-up business owner: a user with a known password who owns one workspace.
 *
 * @param  array<string, mixed>  $user
 * @param  array<string, mixed>  $tenant
 * @return array{0: User, 1: Tenant}
 */
function makeAccount(array $user = [], array $tenant = []): array
{
    $workspace = Tenant::factory()->create($tenant);
    $owner = User::factory()->create(array_merge(['password' => TEST_PASSWORD], $user));
    $workspace->users()->attach($owner->id, ['role' => 'owner']);

    return [$owner, $workspace];
}
