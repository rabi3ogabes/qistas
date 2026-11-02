<?php

use App\Actions\Investors\CreateInvestor;
use App\Documents\DocumentOptions;
use App\Documents\DocumentService;
use App\Documents\Template;
use App\Documents\Templates\ContractAgreement;
use App\Documents\Templates\ContractStatement;
use App\Documents\Templates\CustomerStatement;
use App\Documents\Templates\DayCloseSummary;
use App\Documents\Templates\InvestorReport;
use App\Documents\Templates\PromissoryNote;
use App\Documents\Templates\Receipt;
use App\Documents\Templates\TransactionsReport;
use App\Documents\WorkspaceSetupRequired;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DayClose;
use App\Models\Investor;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Sandbox\TestWorkspace;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Go-live basics (spec 2026-11-01 §3.4): no PDF is made under a placeholder name. Every receipt, statement, contract and
| report goes through DocumentService::issue(), which refuses while the workspace is not confirmed: the app gets a 409
| `workspace_setup_required` to open the first-run card with, a browser goes back to the dashboard with a message.
| Customers, contracts and payments are never held up, and a workspace that never needed the card is never refused.
*/

const SETUP_GATE_MESSAGE = 'Name your business first. It is printed on every receipt.';

/** The kinds of document, named for the tests; every Template class there is. */
const SETUP_GATE_KINDS = ['receipt', 'customer statement', 'contract statement', 'contract agreement', 'promissory note', 'transactions report', 'investor report', 'day close summary'];

/**
 * A workspace with something to print for every kind of document, already unconfirmed: an owner, a customer, a contract
 * with a payment, an investor and a closed day. (Everything is made first and the card taken away last, so the
 * documents have something to say.)
 *
 * @return array{user: User, tenant: Tenant, customer: Customer, contract: Contract, payment: Transaction, investor: Investor, close: DayClose}
 */
function setupGateShop(): array
{
    test()->travelTo('2026-10-21 12:00:00');
    switchOn(Feature::DayClose);
    switchOn(Feature::Investors);
    limitFreePlan(Feature::Investors, null);

    $tenant = closeShop();
    $user = closeStaff($tenant, 'Boss Owner', 'owner');
    $contract = closeContract($tenant);
    $payment = closeTake($contract, '100.00', by: $user);
    $investor = app(CreateInvestor::class)->handle($tenant, ['name' => 'Saeed Partner'], $user);
    $close = closeDay($tenant, '2026-10-21', $user, '100.00');

    $tenant->forceFill(['setup_confirmed_at' => null])->save();

    // Read again inside the workspace, so that each record brings its customer along as the app would find it.
    return asTenant($tenant, fn () => [
        'user' => $user, 'tenant' => $tenant, 'investor' => $investor,
        'customer' => Customer::query()->findOrFail($contract->customer_id),
        'contract' => Contract::query()->findOrFail($contract->id),
        'payment' => Transaction::query()->findOrFail($payment->id),
        'close' => DayClose::query()->findOrFail($close->id),
    ]);
}

function setupGateTemplate(string $kind, array $shop): Template
{
    return match ($kind) {
        'receipt' => new Receipt($shop['payment']),
        'customer statement' => new CustomerStatement($shop['customer']),
        'contract statement' => new ContractStatement($shop['contract']),
        'contract agreement' => new ContractAgreement($shop['contract']),
        'promissory note' => new PromissoryNote($shop['contract']),
        'transactions report' => new TransactionsReport,
        'investor report' => new InvestorReport($shop['investor']),
        'day close summary' => new DayCloseSummary($shop['close']),
    };
}

/** Ask the document service for a document as the workspace's owner would, inside the workspace. */
function setupGateIssue(Template $template, Tenant $tenant, User $by): array
{
    return asTenant($tenant, fn () => app(DocumentService::class)->issue($template, DocumentOptions::from([], $tenant, $by), $tenant, $by));
}

function setupGateConfirm(Tenant $tenant): void
{
    $tenant->forceFill(['setup_confirmed_at' => now()])->save();
}

function setupGateDocuments(): int
{
    return DB::table('documents')->count();
}

describe('the document service', function () {
    it('refuses every kind of document while the workspace is not confirmed, and writes nothing', function (string $kind) {
        $shop = setupGateShop();

        expect(fn () => setupGateIssue(setupGateTemplate($kind, $shop), $shop['tenant'], $shop['user']))->toThrow(WorkspaceSetupRequired::class, SETUP_GATE_MESSAGE);
        expect(setupGateDocuments())->toBe(0);
    })->with(SETUP_GATE_KINDS);

    it('makes the same document once the workspace is confirmed', function (string $kind) {
        $shop = setupGateShop();
        expect(fn () => setupGateIssue(setupGateTemplate($kind, $shop), $shop['tenant'], $shop['user']))->toThrow(WorkspaceSetupRequired::class);

        setupGateConfirm($shop['tenant']);
        $issued = setupGateIssue(setupGateTemplate($kind, $shop), $shop['tenant'], $shop['user']);

        expect($issued['pdf'])->toStartWith('%PDF')->and(setupGateDocuments())->toBe(1);
    })->with(SETUP_GATE_KINDS);

    it('refuses before it looks at the document, so a document that cannot be made still says the name comes first', function () {
        $shop = setupGateShop();
        $template = new class implements Template
        {
            public function kind(): string
            {
                throw new LogicException('The gate must come first.');
            }

            public function subject(): ?Model
            {
                return null;
            }

            public function view(): string
            {
                return '';
            }

            public function data(DocumentOptions $options): array
            {
                throw new LogicException('The gate must come first.');
            }

            public function reference(): string
            {
                return '';
            }

            public function total(): ?string
            {
                return null;
            }

            public function filename(DocumentOptions $options): string
            {
                return '';
            }
        };

        expect(fn () => setupGateIssue($template, $shop['tenant'], $shop['user']))->toThrow(WorkspaceSetupRequired::class);
    });
});

describe('in the API', function () {
    it('answers 409 workspace_setup_required, in the standard error shape', function (string $route) {
        $shop = setupGateShop();
        Sanctum::actingAs($shop['user'], ['app']);
        $url = match ($route) {
            'receipt' => "/api/v1/payments/{$shop['payment']->id}/receipt.pdf",
            'contract statement' => "/api/v1/contracts/{$shop['contract']->id}/statement.pdf",
            'transactions report' => '/api/v1/reports/transactions.pdf',
            'day close summary' => "/api/v1/day-close/{$shop['close']->id}/summary.pdf",
        };

        $this->get($url)->assertStatus(409)->assertExactJson(['error' => ['code' => 'workspace_setup_required', 'message' => SETUP_GATE_MESSAGE]]);

        expect(setupGateDocuments())->toBe(0);
    })->with(['receipt', 'contract statement', 'transactions report', 'day close summary']);

    it('answers in the language of the request', function () {
        $shop = setupGateShop();
        Sanctum::actingAs($shop['user'], ['app']);

        $this->get("/api/v1/payments/{$shop['payment']->id}/receipt.pdf", ['Accept-Language' => 'ar'])
            ->assertStatus(409)->assertJsonPath('error.code', 'workspace_setup_required')
            ->assertJsonPath('error.message', __('Name your business first. It is printed on every receipt.', [], 'ar'));
    });

    it('makes the PDF after the owner confirms', function () {
        $shop = setupGateShop();
        Sanctum::actingAs($shop['user'], ['app']);
        $receipt = "/api/v1/payments/{$shop['payment']->id}/receipt.pdf";
        $summary = "/api/v1/day-close/{$shop['close']->id}/summary.pdf";

        $this->get($receipt)->assertStatus(409);
        $this->get($summary)->assertStatus(409);

        $this->putJson('/api/v1/workspace/setup', ['name' => 'Al-Fares Electronics'])->assertOk();

        $this->get($receipt)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($summary)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(setupGateDocuments())->toBe(2);
    });

    it('never holds up customers, contracts and payments', function () {
        [, $tenant] = apiOwner(['setup_confirmed_at' => null]);

        $customer = $this->postJson('/api/v1/customers', ['name' => 'Ahmad Ali', 'phone' => '+966501234567'])->assertCreated()->json('data.id');
        $contract = $this->postJson('/api/v1/contracts', [
            'customer_id' => $customer, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => '200.00',
            'markup_type' => 'none', 'markup_value' => '0', 'installment_count' => 4, 'frequency' => 'monthly',
            'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/contracts/{$contract}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertCreated();

        expect($tenant->fresh()->isSetUp())->toBeFalse();
    });
});

describe('on the web', function () {
    it('goes back to the dashboard with the warning', function (string $route) {
        $shop = setupGateShop();
        $url = match ($route) {
            'receipt' => "/app/payments/{$shop['payment']->id}/receipt",
            'day close summary' => "/app/day-close/{$shop['close']->id}/summary.pdf",
            'contract statement' => "/app/contracts/{$shop['contract']->id}/statement",
        };

        $this->actingAs($shop['user'])->get($url)->assertRedirect(route('app.dashboard'))->assertSessionHas('warning', SETUP_GATE_MESSAGE);

        expect(setupGateDocuments())->toBe(0);
    })->with(['receipt', 'day close summary', 'contract statement']);

    it('shows the warning on the dashboard it goes back to', function () {
        $shop = setupGateShop();

        $this->actingAs($shop['user'])->followingRedirects()->get("/app/payments/{$shop['payment']->id}/receipt")
            ->assertOk()->assertSee(SETUP_GATE_MESSAGE);
    });

    it('makes the PDF once the workspace is confirmed', function () {
        $shop = setupGateShop();
        $this->actingAs($shop['user'])->get("/app/payments/{$shop['payment']->id}/receipt")->assertRedirect(route('app.dashboard'));

        setupGateConfirm($shop['tenant']);

        $this->actingAs($shop['user'])->get("/app/payments/{$shop['payment']->id}/receipt")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(setupGateDocuments())->toBe(1);
    });

    it('never holds up customers, contracts and payments', function () {
        [$user, $tenant] = owner(['setup_confirmed_at' => null]);
        $customer = customerIn($tenant);
        $contract = openContract($tenant, ['customer_id' => $customer->id]);

        $this->actingAs($user)->get('/app')->assertOk();
        $this->actingAs($user)->get("/app/contracts/{$contract->id}")->assertOk();
        $this->actingAs($user)->post("/app/contracts/{$contract->id}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/app/customers', ['name' => 'Layla Haddad', 'phone' => '+966501234500'])->assertSessionHasNoErrors();

        expect($tenant->fresh()->isSetUp())->toBeFalse()
            ->and(asTenant($tenant, fn () => Transaction::query()->count()))->toBe(1);
    });
});

describe('a workspace that never needed the card', function () {
    it('is never refused: a workspace the migration confirmed', function () {
        $owner = User::factory()->create();
        $migration = 'database/migrations/2026_11_01_000100_add_setup_to_tenants.php';
        $this->artisan('migrate:rollback', ['--path' => $migration, '--force' => true])->assertSuccessful();
        expect(Schema::hasColumn('tenants', 'setup_confirmed_at'))->toBeFalse();

        $id = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $id, 'name' => 'Old Shop', 'slug' => 'old-shop', 'country' => 'SA', 'currency' => 'SAR',
            'status' => 'active', 'created_at' => '2026-03-04 09:15:00', 'updated_at' => '2026-03-04 09:15:00',
        ]);
        $this->artisan('migrate', ['--path' => $migration, '--force' => true])->assertSuccessful();

        $tenant = Tenant::findOrFail($id);
        $tenant->users()->attach($owner->id, ['role' => 'owner']);

        $issued = setupGateIssue(new TransactionsReport, $tenant, $owner);

        expect($issued['pdf'])->toStartWith('%PDF')->and(setupGateDocuments())->toBe(1);
    });

    it('is never refused: a staff test workspace', function () {
        $admin = User::factory()->create(['platform_role' => 'admin']);
        $tenant = app(TestWorkspace::class)->open($admin);

        $issued = setupGateIssue(new TransactionsReport, $tenant, $admin);

        expect($tenant->fresh()->is_test)->toBeTrue()->and($issued['pdf'])->toStartWith('%PDF');
    });

    it('is never refused: the developer seeder’s workspace', function () {
        $this->seed(DemoSeeder::class);
        $owner = User::where('email', DemoSeeder::EMAIL)->sole();
        $tenant = $owner->tenants()->sole();

        $issued = setupGateIssue(new TransactionsReport, $tenant, $owner);

        expect($tenant->isSetUp())->toBeTrue()->and($issued['pdf'])->toStartWith('%PDF');
    });
});
