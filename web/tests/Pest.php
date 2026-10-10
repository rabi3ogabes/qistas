<?php

use App\Actions\CreateContract;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Feature tests hit the database (SQLite in memory, rolled back per test). Vite assets are not built in
| the test run, so views render without the manifest.
*/

require_once __DIR__.'/Support/Fixtures.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/** Run $callback with $tenant as the active workspace (tenant-owned models need one). */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(CurrentTenant::class)->use($tenant, $callback);
}

/** A workspace subscribed to the plan with this key ("free" and "pro" exist after migration). */
function workspaceOn(string $plan = 'free'): Tenant
{
    $tenant = Tenant::factory()->create();
    $tenant->subscribeTo(Plan::where('key', $plan)->sole());

    return $tenant;
}

/**
 * Give the free plan an explicit limit, as the admin would, so a test about how limits behave does not depend on the
 * built-in allowance (Feature::defaultFor), which the product changes over time.
 */
function limitFreePlan(Feature $feature, ?int $limit): void
{
    Plan::where('key', 'free')->sole()->setFeature($feature, true, $limit);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function customerIn(Tenant $tenant, array $attributes = []): Customer
{
    return asTenant($tenant, fn () => Customer::factory()->create($attributes));
}

/**
 * An active contract of $tenant for a new customer: 3 monthly instalments of 100.00 (due 1 Feb, 1 Mar, 1 Apr 2026), no markup.
 *
 * @param  array<string, mixed>  $overrides
 */
function openContract(Tenant $tenant, array $overrides = []): Contract
{
    return app(CreateContract::class)->handle($tenant, array_merge([
        'customer_id' => $overrides['customer_id'] ?? customerIn($tenant)->id, 'type' => 'scheduled', 'principal' => '300.00', 'down_payment' => '0',
        'markup_type' => 'none', 'markup_value' => '0', 'installment_count' => 3, 'frequency' => 'monthly',
        'start_date' => '2026-01-15', 'first_due_date' => '2026-02-01',
    ], $overrides));
}

/** The contract's instalments, oldest first, read fresh from the database. */
function installmentsOfContract(Contract $contract): Collection
{
    return asTenant($contract->tenant, fn () => Installment::where('contract_id', $contract->id)->orderBy('number')->get());
}

/**
 * The ledger invariants that must always hold, however payments and voids were interleaved:
 * every instalment's paid amount is the sum of its allocations, and the contract's net takings are its down
 * payment plus everything paid on instalments.
 */
function expectConsistentLedger(Contract $contract): void
{
    asTenant($contract->tenant, function () use ($contract): void {
        $paidOnInstallments = '0';
        foreach (Installment::where('contract_id', $contract->id)->get() as $installment) {
            $allocated = TransactionAllocation::where('installment_id', $installment->id)->get()
                ->reduce(fn (string $carry, TransactionAllocation $a) => Money::add($carry, $a->amount), '0');
            expect(Money::cmp($installment->paid_amount, $allocated))->toBe(0, "instalment {$installment->number} paid_amount differs from its allocations");
            $paidOnInstallments = Money::add($paidOnInstallments, $installment->paid_amount);
        }

        $net = Transaction::where('contract_id', $contract->id)->get()
            ->reduce(fn (string $carry, Transaction $t) => Money::add($carry, $t->amount), '0');
        $fresh = Contract::find($contract->id);
        expect(Money::cmp($net, Money::add($fresh->down_payment, $paidOnInstallments)))->toBe(0, 'net transactions differ from down payment plus instalments paid');
    });
}

/** The field errors a ValidationException carries; fails the test if the call does not throw one. */
function validationErrors(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('Expected a ValidationException.');
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

/**
 * A signed-up business owner on the Free plan.
 *
 * @param  array<string, mixed>  $tenant
 * @return array{0: User, 1: Tenant}
 */
function owner(array $tenant = []): array
{
    return makeAccount(tenant: $tenant);
}

/**
 * An owner on the Free plan, signed in to the API with an app token (or one with the given abilities).
 *
 * @param  array<string, mixed>  $tenant
 * @param  list<string>  $abilities
 * @return array{0: User, 1: Tenant}
 */
function apiOwner(array $tenant = [], array $abilities = ['app']): array
{
    [$user, $workspace] = owner($tenant);
    Sanctum::actingAs($user, $abilities);

    return [$user, $workspace];
}

/** A new member of $tenant with this role, signed in to the API from now on in the test. */
function apiMember(string $role, Tenant $tenant, array $abilities = ['app']): User
{
    $user = memberAs($role, $tenant);
    Sanctum::actingAs($user, $abilities);

    return $user;
}

/** A new user who belongs to $tenant with the given role (owner, manager, accountant, collector or viewer). */
function memberAs(string $role, Tenant $tenant): User
{
    $user = User::factory()->create();
    $tenant->users()->attach($user->id, ['role' => $role]);

    return $user;
}
