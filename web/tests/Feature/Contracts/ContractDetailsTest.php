<?php

use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Imei;
use Illuminate\Support\Facades\DB;

/*
 * Win Plan PP7 (and the discount at sale from PP6): what was sold, what it cost, the tax, the shop's own contract number
 * and a discount, so profit is real and an IMEI dispute takes ten seconds. Behind the contract_items switch; with it off,
 * a contract is what it always was.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::ContractItems);
});

function detailedBody(Tenant $tenant, array $overrides = []): array
{
    return array_merge([
        'customer_id' => customerIn($tenant)->id, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => '0',
        'markup_type' => 'none', 'installment_count' => 4, 'frequency' => 'monthly', 'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07',
    ], $overrides);
}

describe('a discount at sale', function () {
    it('takes a fixed amount off the price before the down payment', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['discount_type' => 'fixed', 'discount_value' => '200', 'down_payment' => '100']))->assertCreated()
            ->assertJsonPath('data.principal', '1200.00')
            ->assertJsonPath('data.discount_amount', '200.00')
            ->assertJsonPath('data.financed', '900.00')
            ->assertJsonPath('data.installments.0.amount', '225.00');
    });

    it('takes a percent of the price, rounded half up to the cent', function () {
        [, $tenant] = apiOwner();

        // 7.5% of 999.99 is 74.99925: 75.00 off.
        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['principal' => '999.99', 'discount_type' => 'percent', 'discount_value' => '7.5', 'installment_count' => 1]))->assertCreated()
            ->assertJsonPath('data.discount_amount', '75.00')
            ->assertJsonPath('data.financed', '924.99');
    });

    it('refuses a discount as large as the price', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['discount_type' => 'fixed', 'discount_value' => '1200']))->assertStatus(422);
    });

    it('shows in the preview before the contract is made', function () {
        apiOwner();

        $this->postJson('/api/v1/contracts/preview', [
            'principal' => '1000', 'discount_type' => 'percent', 'discount_value' => '10', 'count' => 2, 'frequency' => 'monthly', 'first_due_date' => '2026-11-07',
        ])->assertOk()->assertJsonPath('data.financed', '900.00')->assertJsonPath('data.discount', '100.00');
    });
});

describe('the shop’s own number', function () {
    it('replaces C-0001 wherever the contract is named, and is found by search', function () {
        [, $tenant] = apiOwner();

        $id = $this->postJson('/api/v1/contracts', detailedBody($tenant, ['own_reference' => 'INV-2026-114', 'title' => 'iPhone 16 Pro, 256 GB']))->assertCreated()
            ->assertJsonPath('data.reference', 'INV-2026-114')
            ->assertJsonPath('data.title', 'iPhone 16 Pro, 256 GB')
            ->json('data.id');

        $this->getJson('/api/v1/contracts?q=inv-2026-114')->assertOk()->assertJsonPath('data.0.id', $id);
    });

    it('is unique in the workspace whatever its case, and free in another workspace', function () {
        [, $tenant] = apiOwner();
        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['own_reference' => 'A-7']))->assertCreated();

        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['own_reference' => 'a-7']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['own_reference']]]);

        $other = Tenant::factory()->create();
        asTenant($other, fn () => openContract($other, ['own_reference' => 'A-7']));
        expect(Contract::withoutGlobalScopes()->where('own_reference', 'A-7')->count())->toBe(2);
    });
});

describe('what was sold', function () {
    it('keeps up to ten items, adds their cost into the cost price, and works out the tax in the price', function () {
        [, $tenant] = apiOwner();

        $id = $this->postJson('/api/v1/contracts', detailedBody($tenant, [
            'tax_percent' => '15',
            'items' => [
                ['name' => 'iPhone 16 Pro', 'quantity' => 1, 'serial' => '490154203237518', 'cost' => '900.00', 'price' => '1100.00'],
                ['name' => 'Case', 'quantity' => 2, 'cost' => '20.00', 'price' => '50.00'],
            ],
        ]))->assertCreated()
            ->assertJsonPath('data.cost_price', '940.00')
            ->assertJsonPath('data.tax_percent', '15.00')
            ->assertJsonPath('data.tax_amount', '156.52')
            ->assertJsonPath('data.items.0.serial', '490154203237518')
            ->assertJsonPath('data.items.1.quantity', 2)
            ->json('data.id');

        expect(asTenant($tenant, fn () => ContractItem::query()->where('contract_id', $id)->count()))->toBe(2);
    });

    it('keeps a cost price typed in, over the items’ own costs', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['cost_price' => '1000', 'items' => [['name' => 'Fridge', 'quantity' => 1, 'cost' => '700']]]))
            ->assertCreated()->assertJsonPath('data.cost_price', '1000.00');
    });

    it('checks a 15-digit IMEI and refuses one that cannot be real', function () {
        [, $tenant] = apiOwner();

        expect(Imei::valid('490154203237518'))->toBeTrue()->and(Imei::valid('490154203237517'))->toBeFalse();
        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['items' => [['name' => 'Phone', 'quantity' => 1, 'serial' => '490154203237517']]]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['items.0.serial']]]);
    });

    it('refuses more than ten items', function () {
        [, $tenant] = apiOwner();
        $items = array_fill(0, 11, ['name' => 'Cable', 'quantity' => 1]);

        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['items' => $items]))->assertStatus(422);
    });

    it('warns when a serial is already on another running contract, and still opens the contract', function () {
        [, $tenant] = apiOwner();
        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['items' => [['name' => 'Phone', 'quantity' => 1, 'serial' => 'SN-0042']]]))->assertCreated();

        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['items' => [['name' => 'Phone', 'quantity' => 1, 'serial' => 'sn-0042']]]))->assertCreated()
            ->assertJsonPath('meta.warnings.0.field', 'items.0.serial');
    });

    it('finds a contract by the serial of what was sold', function () {
        [, $tenant] = apiOwner();
        $id = $this->postJson('/api/v1/contracts', detailedBody($tenant, ['items' => [['name' => 'Phone', 'quantity' => 1, 'serial' => '490154203237518']]]))->json('data.id');

        $this->getJson('/api/v1/contracts?q=490154203237518')->assertOk()->assertJsonPath('data.0.id', $id);
    });
});

describe('products', function () {
    it('keeps a simple list of products with a default price and cost', function () {
        [, $tenant] = apiOwner();

        $id = $this->postJson('/api/v1/products', ['name' => 'Galaxy S25', 'sku' => 'S25-256', 'default_price' => '3499', 'cost' => '2900'])->assertCreated()
            ->assertJsonPath('data.default_price', '3499.00')->json('data.id');
        $this->putJson("/api/v1/products/{$id}", ['name' => 'Galaxy S25 256 GB', 'archived' => true])->assertOk()->assertJsonPath('data.archived', true);

        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?archived=1')->assertOk()->assertJsonPath('data.0.name', 'Galaxy S25 256 GB');
        expect(asTenant($tenant, fn () => Product::query()->count()))->toBe(1);
    });

    it('is managed by people who write, never seen across workspaces', function () {
        [, $tenant] = owner();
        apiMember('viewer', $tenant);
        $this->postJson('/api/v1/products', ['name' => 'Thing'])->assertForbidden();

        $foreign = asTenant(Tenant::factory()->create(), fn () => Product::query()->forceCreate(['name' => 'Theirs']));
        $this->putJson("/api/v1/products/{$foreign->id}", ['name' => 'Mine'])->assertNotFound();
    });
});

describe('with the switch off', function () {
    it('opens contracts as before and refuses the new details', function () {
        [, $tenant] = apiOwner();
        DB::table('platform_features')->where('feature_key', 'contract_items')->update(['state' => 'off']);

        $this->postJson('/api/v1/contracts', detailedBody($tenant))->assertCreated();
        $this->postJson('/api/v1/contracts', detailedBody($tenant, ['items' => [['name' => 'Phone', 'quantity' => 1]]]))
            ->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });
});

it('keeps a job or employer on the customer', function () {
    apiOwner();

    $this->postJson('/api/v1/customers', ['name' => 'Omar Khalil', 'phone' => '+966551234567', 'job' => 'Teacher, Riyadh schools'])
        ->assertCreated()->assertJsonPath('data.job', 'Teacher, Riyadh schools');
});

describe('on the web', function () {
    it('asks what was sold and offers a discount, then shows them on the contract with the margin', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->get('/app/contracts/create')->assertOk()->assertSee('What was sold')->assertSee('name="discount_type"', false);

        $this->actingAs($user)->post('/app/contracts', [
            'customer_id' => $customer->id, 'type' => 'scheduled', 'principal' => '1200', 'discount_type' => 'fixed', 'discount_value' => '100',
            'installment_count' => 2, 'frequency' => 'monthly', 'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07',
            'title' => 'iPhone 16 Pro', 'own_reference' => 'INV-77', 'tax_percent' => '15',
            'items' => [['name' => 'iPhone 16 Pro', 'quantity' => '1', 'serial' => '490154203237518', 'cost' => '800', 'price' => '1200'], ['name' => '', 'quantity' => '', 'serial' => '']],
        ])->assertRedirect();
        $contract = asTenant($tenant, fn () => Contract::query()->sole());

        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('INV-77')->assertSee('490154203237518')->assertSee('100.00')->assertSee('Margin')->assertSee('300.00');
    });

    it('opens the contract and warns when a serial is already on another running contract', function () {
        [$user, $tenant] = owner();
        $body = fn () => [
            'customer_id' => customerIn($tenant)->id, 'type' => 'scheduled', 'principal' => '1200',
            'installment_count' => 2, 'frequency' => 'monthly', 'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07',
            'items' => [['name' => 'Phone', 'quantity' => '1', 'serial' => 'SN-0042']],
        ];

        $this->actingAs($user)->post('/app/contracts', $body())->assertSessionMissing('warning');
        $this->actingAs($user)->post('/app/contracts', $body())->assertRedirect()
            ->assertSessionHas('warning', 'SN-0042 is also on contract C-0001, which is still running.');
    });

    it('keeps the products list on its own page', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->post(route('app.products.store'), ['name' => 'Galaxy S25', 'default_price' => '3499', 'cost' => '2900'])->assertRedirect(route('app.products.index'));

        $this->actingAs($user)->get(route('app.products.index'))->assertOk()->assertSee('Galaxy S25')->assertSee('3,499.00');
        $this->actingAs($user)->get('/app')->assertSee(route('app.products.index'), false);
    });

    it('keeps the customer’s job and shows it on their page', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->post('/app/customers', ['name' => 'Huda Saleh', 'phone' => '+966551234567', 'job' => 'Nurse, King Fahd Hospital'])->assertRedirect();
        $customer = asTenant($tenant, fn () => Customer::query()->sole());

        $this->actingAs($user)->get(route('app.customers.show', $customer))->assertSee('Nurse, King Fahd Hospital');
    });
});
