<?php

use App\Actions\CancelContract;
use App\Actions\RecordPayment;
use App\Domain\Investors\InvestorSummary;
use App\Domain\Investors\MainInvestor;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Reports\ContractProgress;
use App\Tenancy\CurrentTenant;

/*
| Win Plan PP12: lists that stay short with hundreds of customers. Sorted by name, by what they owe, by their next due
| date or by their last activity, with the regulars pinned on top; grouped by tags that filter the lists; and finished
| contracts archived out of the way while still counting for the investors who funded them.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

/** @return list<string> the customers' names, in the order the API lists them */
function customerNames(array $query = []): array
{
    return array_column(test()->getJson('/api/v1/customers?'.http_build_query($query))->assertOk()->json('data'), 'name');
}

/**
 * Three customers: Badr owes 300.00 (first due 1 Nov), Amal owes 100.00 (first due 20 Oct), Carim owes nothing.
 *
 * @return array{0: User, 1: Tenant, 2: array<string, Customer>}
 */
function threeCustomers(): array
{
    [$owner, $tenant] = apiOwner();
    $amal = customerIn($tenant, ['name' => 'Amal']);
    $badr = customerIn($tenant, ['name' => 'Badr']);
    $carim = customerIn($tenant, ['name' => 'Carim']);
    openContract($tenant, ['customer_id' => $badr->id, 'principal' => '300.00', 'start_date' => '2026-10-01', 'first_due_date' => '2026-11-01']);
    openContract($tenant, ['customer_id' => $amal->id, 'principal' => '100.00', 'installment_count' => 1, 'start_date' => '2026-10-01', 'first_due_date' => '2026-10-20']);

    return [$owner, $tenant, ['amal' => $amal, 'badr' => $badr, 'carim' => $carim]];
}

describe('sorting', function () {
    it('lists by name unless asked otherwise', function () {
        threeCustomers();

        expect(customerNames())->toBe(['Amal', 'Badr', 'Carim']);
    });

    it('lists by what they owe, most first', function () {
        threeCustomers();

        expect(customerNames(['sort' => 'balance']))->toBe(['Badr', 'Amal', 'Carim']);
    });

    it('lists by the next instalment due, soonest first, and those with nothing due last', function () {
        threeCustomers();

        expect(customerNames(['sort' => 'next_due']))->toBe(['Amal', 'Badr', 'Carim']);
    });

    it('lists by last activity, newest first: a payment brings a customer back to the top', function () {
        [$owner, $tenant, $c] = threeCustomers();
        $this->travel(1)->hours();
        $contract = Contract::withoutGlobalScopes()->where('customer_id', $c['badr']->id)->sole();
        app(RecordPayment::class)->handle($contract, '20.00', 'cash', by: $owner);

        // Without the payment all three were last active at the same moment, and would list by name.
        expect(customerNames(['sort' => 'activity']))->toBe(['Badr', 'Amal', 'Carim']);
    });

    it('keeps pinned customers on top in every order', function () {
        [, , $c] = threeCustomers();

        $this->postJson("/api/v1/customers/{$c['carim']->id}/pin")->assertOk()->assertJsonPath('data.pinned', true);

        expect(customerNames())->toBe(['Carim', 'Amal', 'Badr'])
            ->and(customerNames(['sort' => 'balance']))->toBe(['Carim', 'Badr', 'Amal'])
            ->and(customerNames(['sort' => 'next_due']))->toBe(['Carim', 'Amal', 'Badr']);

        $this->deleteJson("/api/v1/customers/{$c['carim']->id}/pin")->assertOk()->assertJsonPath('data.pinned', false);
        expect(customerNames())->toBe(['Amal', 'Badr', 'Carim']);
    });

    it('refuses an order that does not exist', function () {
        threeCustomers();

        $this->getJson('/api/v1/customers?sort=shoe_size')->assertStatus(422);
    });

    it('does not fill the activity log with pins', function () {
        [, $tenant, $c] = threeCustomers();

        $this->postJson("/api/v1/customers/{$c['carim']->id}/pin")->assertOk();

        expect(AuditLog::where('tenant_id', $tenant->id)->where('action', 'customer.updated')->exists())->toBeFalse();
    });
});

describe('tags', function () {
    beforeEach(fn () => switchOn(Feature::CustomerTags));

    it('groups customers under tags that filter the list', function () {
        [, , $c] = threeCustomers();

        $shop = $this->postJson('/api/v1/tags', ['name' => 'Shop 2', 'colour' => 'blue'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/customers/{$c['badr']->id}", ['name' => 'Badr', 'phone' => $c['badr']->phone, 'tags' => [$shop]])->assertOk()
            ->assertJsonPath('data.tags.0.name', 'Shop 2');

        expect(customerNames(['tag' => $shop]))->toBe(['Badr']);
        $this->getJson('/api/v1/tags')->assertOk()->assertJsonPath('data.0.name', 'Shop 2')->assertJsonPath('data.0.customers', 1);
    });

    it('leaves a customer’s tags alone when an update does not mention them, and takes them off when sent empty', function () {
        [, , $c] = threeCustomers();
        $tag = $this->postJson('/api/v1/tags', ['name' => 'Shop 2'])->json('data.id');
        $this->putJson("/api/v1/customers/{$c['badr']->id}", ['name' => 'Badr', 'phone' => $c['badr']->phone, 'tags' => [$tag]])->assertOk();

        $this->putJson("/api/v1/customers/{$c['badr']->id}", ['name' => 'Badr Saleh', 'phone' => $c['badr']->phone])->assertOk()
            ->assertJsonPath('data.tags.0.name', 'Shop 2');
        $this->putJson("/api/v1/customers/{$c['badr']->id}", ['name' => 'Badr Saleh', 'phone' => $c['badr']->phone, 'tags' => []])->assertOk()
            ->assertJsonPath('data.tags', []);
    });

    it('keeps tag names unique in a business, whatever their case', function () {
        threeCustomers();

        $this->postJson('/api/v1/tags', ['name' => 'Government staff'])->assertCreated();
        $this->postJson('/api/v1/tags', ['name' => 'government STAFF'])->assertStatus(422);
    });

    it('never uses another business’s tag', function () {
        [, , $c] = threeCustomers();
        $other = $this->postJson('/api/v1/tags', ['name' => 'Mine'])->json('data.id');
        apiOwner();
        $this->postJson('/api/v1/tags', ['name' => 'Theirs'])->assertCreated();

        $this->getJson('/api/v1/tags')->assertJsonCount(1, 'data');
        $customer = $this->postJson('/api/v1/customers', ['name' => 'Someone', 'phone' => '+966551112233', 'tags' => [$other]])->assertStatus(422);
    });

    it('lets go of a tag without touching its customers', function () {
        [, , $c] = threeCustomers();
        $tag = $this->postJson('/api/v1/tags', ['name' => 'Shop 2'])->json('data.id');
        $this->putJson("/api/v1/customers/{$c['badr']->id}", ['name' => 'Badr', 'phone' => $c['badr']->phone, 'tags' => [$tag]])->assertOk();

        $this->deleteJson("/api/v1/tags/{$tag}")->assertNoContent();

        expect(customerNames())->toContain('Badr')
            ->and($this->getJson("/api/v1/customers/{$c['badr']->id}")->json('data.tags'))->toBe([]);
    });

    it('is a feature the platform can switch off; customers still list', function () {
        app(FeatureControl::class)->setState(Feature::CustomerTags, PlatformState::Off, null, null);
        threeCustomers();

        $this->postJson('/api/v1/tags', ['name' => 'Shop 2'])->assertStatus(403)->assertJsonPath('error.code', 'feature_unavailable');
        expect(customerNames())->toBe(['Amal', 'Badr', 'Carim']);
    });
});

describe('archiving contracts', function () {
    it('archives a cancelled or settled contract, and hides it from the lists until asked', function () {
        [$owner, $tenant, $c] = threeCustomers();
        $cancelled = Contract::withoutGlobalScopes()->where('customer_id', $c['badr']->id)->sole();
        app(CancelContract::class)->handle($cancelled, 'Returned', $owner);
        $settled = Contract::withoutGlobalScopes()->where('customer_id', $c['amal']->id)->sole();
        app(RecordPayment::class)->handle($settled, '100.00', 'cash', by: $owner);

        $this->postJson("/api/v1/contracts/{$cancelled->id}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->postJson("/api/v1/contracts/{$settled->id}/archive")->assertOk();

        expect($this->getJson('/api/v1/contracts?status=all')->json('data'))->toBe([])
            ->and($this->getJson('/api/v1/contracts?status=cancelled')->json('data'))->toBe([])
            ->and(array_column($this->getJson('/api/v1/contracts?status=archived')->json('data'), 'reference'))->toEqualCanonicalizing(['C-0001', 'C-0002']);

        $this->postJson("/api/v1/contracts/{$cancelled->id}/unarchive")->assertOk()->assertJsonPath('data.archived', false);
        expect(array_column($this->getJson('/api/v1/contracts?status=cancelled')->json('data'), 'reference'))->toBe(['C-0001']);
    });

    it('counts archived contracts on their own tab, and in no other', function () {
        [$owner, $tenant, $c] = threeCustomers();
        $cancelled = Contract::withoutGlobalScopes()->where('customer_id', $c['badr']->id)->sole();
        app(CancelContract::class)->handle($cancelled, 'Returned', $owner);
        $this->postJson("/api/v1/contracts/{$cancelled->id}/archive")->assertOk();

        $counts = app(CurrentTenant::class)->use($tenant, fn () => app(ContractProgress::class)->counts());

        expect($counts)->toMatchArray(['all' => 1, 'active' => 1, 'cancelled' => 0, 'archived' => 1]);
    });

    it('never archives a contract that is still running', function () {
        [, , $c] = threeCustomers();
        $running = Contract::withoutGlobalScopes()->where('customer_id', $c['badr']->id)->sole();

        $this->postJson("/api/v1/contracts/{$running->id}/archive")->assertStatus(422)->assertJsonPath('error.code', 'contract_running');
    });

    it('still counts an archived contract for the investor who funded it', function () {
        [$owner, $tenant, $c] = threeCustomers();
        $settled = Contract::withoutGlobalScopes()->where('customer_id', $c['amal']->id)->sole();
        app(RecordPayment::class)->handle($settled, '100.00', 'cash', by: $owner);
        $before = InvestorSummary::for(MainInvestor::for($tenant));

        $this->postJson("/api/v1/contracts/{$settled->id}/archive")->assertOk();

        expect(InvestorSummary::for(MainInvestor::for($tenant)))->toBe($before)->and($before['contracts'])->toBe(2);
    });
});

describe('on the web', function () {
    it('sorts, filters by tag and shows pins on the customer list', function () {
        switchOn(Feature::CustomerTags);
        [$owner, $tenant, $c] = threeCustomers();
        $c['carim']->forceFill(['pinned_at' => now()])->save();

        $this->actingAs($owner)->get(route('app.customers.index', ['sort' => 'balance']))->assertOk()
            ->assertSee('name="sort"', false)->assertSeeInOrder(['Carim', 'Badr', 'Amal'])->assertSee('data-pinned', false);
    });

    it('folds archived contracts away on the customer’s page, with no empty table when all are archived', function () {
        [$owner, $tenant, $c] = threeCustomers();
        $contract = Contract::withoutGlobalScopes()->where('customer_id', $c['badr']->id)->sole();
        app(CancelContract::class)->handle($contract, 'Returned', $owner);
        $this->actingAs($owner)->post(route('app.contracts.archive', $contract))->assertRedirect();

        $this->actingAs($owner)->get(route('app.customers.show', $c['badr']))->assertOk()
            ->assertSee('Archived contracts: 1')->assertSee('Every contract for this customer is archived.')
            ->assertDontSee('<table', false);
        $this->actingAs($owner)->get(route('app.customers.show', $c['amal']))->assertOk()
            ->assertSee('<table', false)->assertDontSee('Archived contracts');
    });

    it('pins a customer and archives a finished contract from their pages', function () {
        [$owner, $tenant, $c] = threeCustomers();
        $contract = Contract::withoutGlobalScopes()->where('customer_id', $c['badr']->id)->sole();
        app(CancelContract::class)->handle($contract, 'Returned', $owner);

        $this->actingAs($owner)->post(route('app.customers.pin', $c['badr']))->assertRedirect();
        $this->actingAs($owner)->post(route('app.contracts.archive', $contract))->assertRedirect();

        expect(Customer::withoutGlobalScopes()->find($c['badr']->id)->pinned_at)->not->toBeNull()
            ->and(Contract::withoutGlobalScopes()->find($contract->id)->archived_at)->not->toBeNull();
        $this->actingAs($owner)->get(route('app.contracts.index', ['status' => 'archived']))->assertSee('C-0001');
    });
});
