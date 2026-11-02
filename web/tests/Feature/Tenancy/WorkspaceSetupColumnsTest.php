<?php

use App\Models\Tenant;
use App\Models\User;
use App\Sandbox\TestWorkspace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| A workspace knows whether its owner has confirmed the setup (the business name and the place), and how its country was
| chosen. Every workspace that exists before this was added counts as confirmed; so does every one a test makes.
*/

const SETUP_COLUMNS_MIGRATION = 'database/migrations/2026_11_01_000100_add_setup_to_tenants.php';

/** Run (or undo) only the setup-columns migration, through the real migrator. */
function setupColumnsMigrate(string $command): void
{
    test()->artisan($command, ['--path' => SETUP_COLUMNS_MIGRATION, '--force' => true])->assertSuccessful();
}

/** A workspace row as it was written before the setup columns existed (no model, no factory). */
function setupColumnsLegacyTenant(string $slug, string $createdAt): string
{
    $id = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $id, 'name' => 'Old '.$slug, 'slug' => $slug, 'country' => 'SA', 'currency' => 'SAR',
        'status' => 'active', 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);

    return $id;
}

it('is confirmed when the factory makes a workspace', function () {
    $tenant = Tenant::factory()->create()->fresh();

    expect($tenant->setup_confirmed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($tenant->setup_confirmed_at->isToday())->toBeTrue()
        ->and($tenant->isSetUp())->toBeTrue()
        ->and($tenant->country_source)->toBeNull();
});

it('is set up only while setup_confirmed_at is not null', function () {
    $tenant = Tenant::factory()->create(['setup_confirmed_at' => null])->fresh();

    expect($tenant->isSetUp())->toBeFalse();

    $tenant->setup_confirmed_at = now()->subDay();
    $tenant->save();

    expect($tenant->fresh()->isSetUp())->toBeTrue();

    $tenant->setup_confirmed_at = null;
    $tenant->save();

    expect($tenant->fresh()->isSetUp())->toBeFalse();
});

it('confirms every workspace that exists when the migration runs', function () {
    setupColumnsMigrate('migrate:rollback');

    expect(Schema::hasColumns('tenants', ['setup_confirmed_at', 'country_source']))->toBeFalse();

    $first = setupColumnsLegacyTenant('old-shop-one', '2026-03-04 09:15:00');
    $second = setupColumnsLegacyTenant('old-shop-two', '2026-09-30 22:05:41');

    setupColumnsMigrate('migrate');

    expect(Schema::hasColumns('tenants', ['setup_confirmed_at', 'country_source']))->toBeTrue();

    foreach ([$first, $second] as $id) {
        $row = DB::table('tenants')->where('id', $id)->first();

        expect($row->setup_confirmed_at)->toBe($row->created_at)
            ->and($row->country_source)->toBeNull();
    }

    expect(Tenant::find($first)->isSetUp())->toBeTrue()
        ->and(Tenant::find($second)->setup_confirmed_at->format('Y-m-d H:i:s'))->toBe('2026-09-30 22:05:41');
});

it('confirms a staff test workspace when it is made', function () {
    $admin = User::factory()->create(['platform_role' => 'admin']);

    $tenant = app(TestWorkspace::class)->open($admin)->fresh();

    expect($tenant->is_test)->toBeTrue()
        ->and($tenant->isSetUp())->toBeTrue();

    $again = app(TestWorkspace::class)->reset($admin)->fresh();

    expect($again->isSetUp())->toBeTrue();
});

it('does not let setup_confirmed_at be mass assigned', function () {
    expect(fn () => new Tenant(['name' => 'Al-Fares', 'setup_confirmed_at' => now()]))->toThrow(MassAssignmentException::class)
        ->and(fn () => Tenant::create([
            'name' => 'Al-Fares', 'slug' => 'al-fares', 'country' => 'SA', 'currency' => 'SAR', 'setup_confirmed_at' => now(),
        ]))->toThrow(MassAssignmentException::class);
});

it('lets the label of how the country was chosen be mass assigned', function () {
    $tenant = new Tenant(['name' => 'Al-Fares', 'country_source' => 'header']);

    expect($tenant->country_source)->toBe('header');
});
