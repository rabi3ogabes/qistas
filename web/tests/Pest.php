<?php

use App\Actions\CloseDay;
use App\Actions\CreateContract;
use App\Actions\Investors\RecordInvestorEntry;
use App\Actions\RecordPayment;
use App\Actions\ReopenDay;
use App\Domain\DayClose\DayTotals;
use App\Domain\Investors\MainInvestor;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DayClose;
use App\Models\Installment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| Feature tests hit the database (SQLite in memory, rolled back per test). Vite assets are not built in
| the test run, so views render without the manifest.
*/

require_once __DIR__.'/Support/Fixtures.php';
require_once __DIR__.'/Support/Imports.php';
require_once __DIR__.'/Support/Pdf.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->withoutVite();

        // The error center's spool is a file in storage/logs. A test that breaks the database after an exception is reported
        // would append to the repository's own file, and a developer's server would import it on its next run. So every test
        // gets a spool of its own in the system's temporary folder (the Errors tests that look inside the spool point it at
        // a place of theirs, which wins). Nothing is created until something is written.
        $this->suiteErrorSpool = sys_get_temp_dir().DIRECTORY_SEPARATOR.'qistas-suite-spool-'.bin2hex(random_bytes(8)).'.ndjson';
        config(['qistas.errors.spool_path' => $this->suiteErrorSpool]);
    })
    ->afterEach(function () {
        $spool = $this->suiteErrorSpool ?? null;

        foreach ($spool === null ? [] : [$spool, $spool.'.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    })
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

/** Switch a feature on for everyone, as the admin does in the Feature Control cockpit (new features ship off). */
function switchOn(Feature $feature): void
{
    app(FeatureControl::class)->setState($feature, PlatformState::On, null, null);
}

/** Switch a feature off for everyone again, as the admin does (what exists stays; nothing new can be added). */
function switchOff(Feature $feature): void
{
    // A reason, because the cockpit asks for one whenever a workspace used the feature in the last 30 days.
    app(FeatureControl::class)->setState($feature, PlatformState::Off, 'switched off by a test', null);
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

/*
| End-of-day close (daily-habit wave A, H4): a workspace on its own clock, its team, a contract with room for many
| payments, money taken on it, the day's figures and a close, shared by the tests in tests/Feature/DayClose.
*/

/** A workspace on the Free plan whose clock is [$timezone]. */
function closeShop(string $country = 'SA', string $timezone = 'Asia/Riyadh'): Tenant
{
    $tenant = Tenant::factory()->create(['country' => $country, 'timezone' => $timezone]);
    $tenant->subscribeTo(Plan::where('key', 'free')->sole());

    return $tenant;
}

/** Someone who takes payments for [$tenant], with a name the tests can look for. */
function closeStaff(Tenant $tenant, string $name, string $role = 'collector'): User
{
    $user = User::factory()->create(['name' => $name]);
    $tenant->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * A contract with room for many payments (ten instalments of 500.00) for a customer whose name the tests know. The
 * customer keeps a phone number and a national ID, so a test can prove neither ever reaches the totals.
 *
 * @param  array<string, mixed>  $overrides
 */
function closeContract(Tenant $tenant, string $customer = 'Ahmad Ali', array $overrides = []): Contract
{
    $owner = customerIn($tenant, ['name' => $customer, 'phone' => '+966501234567', 'national_id' => '1098765432', 'email' => 'ahmad@example.test']);

    return openContract($tenant, array_merge(['customer_id' => $owner->id, 'principal' => '5000.00', 'installment_count' => 10], $overrides));
}

/** Money taken on [$contract]: now, or at the UTC moment [$at] (a back-dated or earlier payment). */
function closeTake(Contract $contract, string $amount, string $method = 'cash', ?User $by = null, ?string $at = null): Transaction
{
    return app(RecordPayment::class)->handle($contract, $amount, $method, by: $by, paidAt: $at === null ? null : CarbonImmutable::parse($at, 'UTC'));
}

/** The workspace's figures for the local day [$date]. */
function closeTotals(Tenant $tenant, string $date = '2026-10-21'): array
{
    return DayTotals::for($tenant, WorkspaceDay::on($tenant, $date));
}

/** [$by] closes the local day [$date] of [$tenant], having counted [$counted] in the till. */
function closeDay(Tenant $tenant, string $date, User $by, string $counted = '0.00', ?string $note = null): DayClose
{
    return app(CloseDay::class)->handle($tenant, WorkspaceDay::on($tenant, $date), $counted, $note, $by);
}

/**
 * What the contract form sends for a sale of 1,200.00 to the customer of [$contract]: [$downPayment] down and four
 * monthly instalments from 25 Nov, the contract dated [$start] (null sends no date, as an API client may).
 *
 * @return array<string, mixed>
 */
function saleOn(Contract $contract, ?string $start, string $downPayment = '200.00'): array
{
    return [
        'customer_id' => $contract->customer_id, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => $downPayment,
        'markup_type' => 'none', 'markup_value' => '0', 'installment_count' => 4, 'frequency' => 'monthly',
        'first_due_date' => '2026-11-25',
    ] + ($start === null ? [] : ['start_date' => $start]);
}

/** [$by] reopens [$close], giving [$reason]. */
function reopenDay(DayClose $close, string $reason, User $by): DayClose
{
    return app(ReopenDay::class)->handle($close, $reason, $by);
}

/*
| Go-live basics, the region lock: money a workspace can record before it has any contract or payment. Each fixes the
| country, its currency and the time zone just as a contract does (ConfirmWorkspaceSetup::regionIsOpen).
*/

/** The kinds of money recordMoneyWithoutAContract() can record. */
const MONEY_WITHOUT_A_CONTRACT = ['an investor deposit', 'a closed day'];

/** [$by] records money in [$tenant] as [$kind] says, with no contract and no payment anywhere in it. */
function recordMoneyWithoutAContract(string $kind, Tenant $tenant, User $by): void
{
    if ($kind === 'an investor deposit') {
        switchOn(Feature::Investors);
        app(RecordInvestorEntry::class)->handle(MainInvestor::for($tenant), 'deposit', '1000.00', by: $by);
    } elseif ($kind === 'a closed day') {
        switchOn(Feature::DayClose);
        app(CloseDay::class)->handle($tenant, WorkspaceDay::of($tenant), '150.00', null, $by);
    } else {
        throw new InvalidArgumentException("No money of the kind [{$kind}].");
    }

    expect(DB::table('contracts')->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(DB::table('transactions')->where('tenant_id', $tenant->id)->count())->toBe(0);
}

/**
 * The audit rows of the end-of-day close in the workspace [$tenantId] whose action is like [$action], oldest first (and,
 * for one change that touched two days, in the order of the days): what, about which close, by whom, and the details.
 *
 * @return list<array{action: string, subject_id: ?string, user_id: ?string, changes: ?array<string, mixed>}>
 */
function closeAudits(string $tenantId, string $action = 'day_close.%'): array
{
    return AuditLog::query()->where('tenant_id', $tenantId)->where('action', 'like', $action)->get()
        ->sortBy(fn (AuditLog $row) => [$row->created_at?->getTimestamp(), $row->changes['day'] ?? ''])->values()
        ->map(fn (AuditLog $row) => ['action' => $row->action, 'subject_id' => $row->subject_id, 'user_id' => $row->user_id, 'changes' => $row->changes])
        ->all();
}
