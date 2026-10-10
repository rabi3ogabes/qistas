<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/*
 * The free plan the Win Plan promises (6.4): twenty customers, twice the leading rival's ten, every contract they
 * need, and five documents a month. What is advertised and what is enforced come from the same settings.
 */

it('lets a free workspace keep twenty customers and refuses the twenty-first', function () {
    [, $tenant] = apiOwner();
    foreach (range(1, 20) as $i) {
        customerIn($tenant, ['name' => sprintf('Customer %02d', $i)]);
    }

    $this->postJson('/api/v1/customers', ['name' => 'Customer 21', 'phone' => '+966501112233'])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'limit_reached')
        ->assertJsonPath('error.feature', 'customers')
        ->assertJsonPath('error.limit', 20);

    expect(asTenant($tenant, fn () => Customer::count()))->toBe(20);
});

it('puts no limit on how many contracts a free workspace runs', function () {
    $tenant = workspaceOn('free');

    $entitlement = Entitlements::for($tenant)->check(Feature::ActiveContracts);

    expect($entitlement->enabled())->toBeTrue()->and($entitlement->limit())->toBeNull();
});

it('gives a free workspace five documents a month', function () {
    $tenant = workspaceOn('free');

    expect(Entitlements::for($tenant)->check(Feature::PdfStatements)->limit())->toBe(5);
});

it('advertises the same numbers on the plans list', function () {
    $free = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->firstWhere('key', 'free');

    expect($free['features']['customers'])->toMatchArray(['enabled' => true, 'limit' => 20])
        ->and($free['features']['active_contracts'])->toMatchArray(['enabled' => true, 'limit' => null])
        ->and($free['features']['pdf_statements'])->toMatchArray(['enabled' => true, 'limit' => 5]);
});

it('raises a stored free setting that still has the old number, and leaves one the admin chose alone', function () {
    $free = Plan::where('key', 'free')->sole();
    $free->features()->create(['feature_key' => 'customers', 'enabled' => true, 'limit_value' => 5]);
    $free->features()->create(['feature_key' => 'pdf_statements', 'enabled' => true, 'limit_value' => 12]);

    $migration = require database_path('migrations/2026_10_11_000100_raise_free_plan.php');
    $migration->up();

    $limits = DB::table('plan_features')->where('plan_id', $free->id)->pluck('limit_value', 'feature_key');

    expect($limits['customers'])->toBe(20)->and($limits['pdf_statements'])->toBe(12);
});
