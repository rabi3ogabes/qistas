<?php

use App\Actions\RecordPayment;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Format;
use App\Support\Money;

function contractForm(Customer $customer, array $overrides = []): array
{
    return array_merge([
        'customer_id' => $customer->id, 'type' => 'scheduled', 'principal' => '1200.00', 'down_payment' => '200.00',
        'markup_type' => 'percent', 'markup_value' => '10', 'installment_count' => '4', 'frequency' => 'monthly',
        'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07', 'notes' => '',
    ], $overrides);
}

function contractsIn(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Contract::count());
}

function customerNameOf(Contract $contract): string
{
    return asTenant($contract->tenant, fn () => $contract->customer->name);
}

beforeEach(fn () => $this->travelTo('2026-10-07 12:00:00'));

describe('the list', function () {
    it('sends guests to sign in', function () {
        $this->get('/app/contracts')->assertRedirect(route('login'));
    });

    it('lists this workspace’s contracts, newest first, with customer, total and what is owed', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $first = openContract($tenant, ['first_due_date' => '2026-11-01']);
        $second = openContract($tenant, ['first_due_date' => '2026-11-01']);
        $foreign = openContract(Tenant::factory()->create());

        $this->actingAs($user)->get('/app/contracts')->assertOk()
            ->assertSeeInOrder([$second->reference(), customerNameOf($second), $first->reference(), customerNameOf($first)])
            ->assertSee(Format::money('300.00', 'SAR'))->assertSee('data-status="active"', false)
            ->assertDontSee(customerNameOf($foreign));
    });

    it('shows the next instalment due and its amount', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant, ['first_due_date' => '2026-11-01']);
        app(RecordPayment::class)->handle($contract, '100.00', 'cash'); // the first is paid, so the next is 1 Dec

        $this->actingAs($user)->get('/app/contracts')->assertSee('1 Dec 2026')->assertDontSee('1 Nov 2026')->assertSee(Format::money('100.00', 'SAR'));
    });

    it('flags a contract with an overdue instalment as late', function () {
        [$user, $tenant] = owner();
        openContract($tenant, ['first_due_date' => '2026-09-01']);

        $this->actingAs($user)->get('/app/contracts')->assertSee('data-status="late"', false)->assertDontSee('data-status="active"', false);
    });

    it('does not call an instalment due today late', function () {
        [$user, $tenant] = owner();
        openContract($tenant, ['first_due_date' => '2026-10-07']);

        $this->actingAs($user)->get('/app/contracts')->assertSee('data-status="active"', false)->assertDontSee('data-status="late"', false);
    });

    it('filters by status', function () {
        [$user, $tenant] = owner();
        $active = openContract($tenant, ['first_due_date' => '2026-11-01']);
        $late = openContract($tenant, ['first_due_date' => '2026-09-01']);
        $settled = openContract($tenant);
        app(RecordPayment::class)->handle($settled, '300.00', 'cash');
        $cancelled = openContract($tenant);
        asTenant($tenant, fn () => Contract::find($cancelled->id)->forceFill(['status' => 'cancelled'])->save());

        $see = fn (string $status) => $this->actingAs($user)->get('/app/contracts?status='.$status)->assertOk();

        $see('active')->assertSee($active->reference())->assertSee($late->reference())->assertDontSee($settled->reference())->assertDontSee($cancelled->reference());
        $see('late')->assertSee($late->reference())->assertDontSee($active->reference())->assertDontSee($settled->reference());
        $see('settled')->assertSee($settled->reference())->assertDontSee($active->reference())->assertDontSee($late->reference());
        $see('cancelled')->assertSee($cancelled->reference())->assertDontSee($active->reference())->assertDontSee($late->reference());
        $see('all')->assertSee($cancelled->reference())->assertSee($active->reference())->assertSee($settled->reference())->assertSee($late->reference());
    });

    it('shows running contracts first and by default, not finished ones', function () {
        [$user, $tenant] = owner();
        $running = openContract($tenant, ['first_due_date' => '2026-11-01']);
        $settled = openContract($tenant);
        app(RecordPayment::class)->handle($settled, '300.00', 'cash');

        $this->actingAs($user)->get('/app/contracts')->assertSee($running->reference())->assertDontSee($settled->reference());
    });

    it('ignores an unknown status instead of failing', function () {
        [$user, $tenant] = owner();
        openContract($tenant);

        $this->actingAs($user)->get('/app/contracts?status=%3Cscript%3E')->assertOk()->assertDontSee('<script>', false);
    });

    it('finds contracts by reference or customer name', function (string $term) {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $other = openContract($tenant);
        asTenant($tenant, fn () => Customer::find($contract->customer_id)->forceFill(['name' => 'Layla Haddad'])->save());

        $this->actingAs($user)->get('/app/contracts?status=all&q='.urlencode($term))->assertOk()
            ->assertSee($contract->reference())->assertDontSee($other->reference());
    })->with(['reference' => ['C-0001'], 'bare number' => ['1'], 'lower case' => ['c-0001'], 'customer' => ['haddad']]);

    it('finds contracts by the customer’s phone number, which is longer than a contract number', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $other = openContract($tenant);
        asTenant($tenant, fn () => Customer::find($contract->customer_id)->forceFill(['phone' => '+966501234567'])->save());
        asTenant($tenant, fn () => Customer::find($other->customer_id)->forceFill(['phone' => '+966559990000'])->save());

        $this->actingAs($user)->get('/app/contracts?status=all&q=501234')->assertOk()
            ->assertSee($contract->reference())->assertDontSee($other->reference());
    });

    it('says when nothing matches the search', function () {
        [$user, $tenant] = owner();
        openContract($tenant);

        $this->actingAs($user)->get('/app/contracts?status=all&q=zzzz')->assertSee('No contracts match');
    });

    it('invites you to open the first contract', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/contracts')->assertSee('No contracts yet')->assertSee(route('app.contracts.create'), false);
    });

    it('pages through a long list', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        foreach (range(1, 25) as $_) {
            openContract($tenant, ['type' => 'cash', 'principal' => '10.00']);
        }

        $this->actingAs($user)->get('/app/contracts')->assertSee('C-0025')->assertDontSee('C-0005')->assertSee('page=2', false);
    });

    it('is open to a viewer, who cannot open a contract', function () {
        [, $tenant] = owner();
        openContract($tenant);

        $this->actingAs(memberAs('viewer', $tenant))->get('/app/contracts')->assertOk()->assertSee('C-0001')->assertDontSee(route('app.contracts.create'), false);
    });
});

describe('opening a contract', function () {
    it('shows the form with the plan usage and the customers to choose from', function () {
        [$user, $tenant] = owner();
        customerIn($tenant, ['name' => 'Ahmad']);
        customerIn($tenant, ['name' => 'Zainab']);
        customerIn(Tenant::factory()->create(), ['name' => 'Someone Else']);
        $deleted = customerIn($tenant, ['name' => 'Deleted One']);
        asTenant($tenant, fn () => $deleted->delete());

        $this->actingAs($user)->get('/app/contracts/create')->assertOk()
            ->assertSee('name="principal"', false)->assertSee('name="installment_count"', false)->assertSee('name="first_due_date"', false)
            ->assertSeeInOrder(['Ahmad', 'Zainab'])->assertDontSee('Someone Else')->assertDontSee('Deleted One')
            ->assertSee('0 of 5');
    });

    it('preselects the customer it was opened from', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['name' => 'Layla Haddad']);
        customerIn($tenant, ['name' => 'Someone Else']);

        $this->actingAs($user)->get('/app/contracts/create?customer='.$customer->id)->assertOk()->assertSee('value="'.$customer->id.'" selected', false);
    });

    it('does not preselect a customer from another workspace', function () {
        [$user, $tenant] = owner();
        $mine = customerIn($tenant);
        $foreign = customerIn(Tenant::factory()->create());

        $this->actingAs($user)->get('/app/contracts/create?customer='.$foreign->id)->assertOk()
            ->assertSee('value="'.$mine->id.'"', false)->assertDontSee('value="'.$mine->id.'" selected', false)
            ->assertDontSee('value="'.$foreign->id.'"', false);
    });

    it('asks for a customer first when there is none', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/contracts/create')->assertOk()
            ->assertSee('Add a customer first')->assertSee(route('app.customers.create'), false)->assertDontSee('name="principal"', false);
    });

    it('offers the upgrade instead of the form at the plan limit', function () {
        [$user, $tenant] = owner();
        foreach (range(1, 5) as $_) {
            openContract($tenant);
        }

        $this->actingAs($user)->get('/app/contracts/create')->assertOk()
            ->assertSee('You have reached your plan limit')->assertDontSee('name="principal"', false);
    });

    it('is refused to a viewer', function () {
        [, $tenant] = owner();

        $this->actingAs(memberAs('viewer', $tenant))->get('/app/contracts/create')->assertForbidden();
    });
});

describe('saving a contract', function () {
    it('creates the contract and its instalments and opens it', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $response = $this->actingAs($user)->post('/app/contracts', contractForm($customer));

        $contract = asTenant($tenant, fn () => Contract::sole());
        $response->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('status');
        expect($contract->customer_id)->toBe($customer->id)->and($contract->created_by_user_id)->toBe($user->id)
            ->and(asTenant($tenant, fn () => Installment::count()))->toBe(4)
            ->and(asTenant($tenant, fn () => Transaction::where('type', 'down_payment')->count()))->toBe(1);
    });

    it('opens a cash sale', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->post('/app/contracts', ['customer_id' => $customer->id, 'type' => 'cash', 'principal' => '450.00', 'start_date' => '2026-10-07']);

        $contract = asTenant($tenant, fn () => Contract::sole());
        expect($contract->type)->toBe('cash')->and(asTenant($tenant, fn () => Installment::count()))->toBe(1);
    });

    it('lets a cash sale ignore instalment fields that would fail a plan', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->post('/app/contracts', contractForm($customer, ['type' => 'cash', 'principal' => '450.00', 'down_payment' => '9999', 'frequency' => 'daily', 'installment_count' => '0', 'first_due_date' => '2020-01-01']))
            ->assertSessionHasNoErrors();

        $contract = asTenant($tenant, fn () => Contract::sole());
        expect($contract->type)->toBe('cash')->and(Money::cmp($contract->down_payment, '0'))->toBe(0)
            ->and(Money::cmp($contract->total, '450'))->toBe(0);
    });

    it('reads amounts and dates typed on an Arabic keyboard', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->post('/app/contracts', contractForm($customer, ['principal' => '١٢٠٠٫٠٠', 'installment_count' => '٤', 'first_due_date' => '٢٠٢٦-١١-٠٧']));

        $contract = asTenant($tenant, fn () => Contract::sole());
        expect(Money::cmp($contract->principal, '1200'))->toBe(0)->and($contract->installment_count)->toBe(4);
    });

    it('explains what is wrong, creating nothing', function (array $changes, string $field) {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->from('/app/contracts/create')->post('/app/contracts', contractForm($customer, $changes))
            ->assertRedirect('/app/contracts/create')->assertSessionHasErrors($field);
        expect(contractsIn($tenant))->toBe(0);
    })->with([
        'no price' => [['principal' => ''], 'principal'],
        'down payment too high' => [['down_payment' => '1200'], 'down_payment'],
        'no instalments' => [['installment_count' => '0'], 'installment_count'],
        'bad date' => [['first_due_date' => '2026-02-30'], 'first_due_date'],
        'due before the contract' => [['first_due_date' => '2026-10-01'], 'first_due_date'],
        'unknown frequency' => [['frequency' => 'daily'], 'frequency'],
    ]);

    it('will not take a customer from another workspace', function () {
        [$user, $tenant] = owner();
        $foreign = customerIn(Tenant::factory()->create());

        $this->actingAs($user)->from('/app/contracts/create')->post('/app/contracts', contractForm($foreign))->assertSessionHasErrors('customer_id');
        expect(contractsIn($tenant))->toBe(0);
    });

    it('ignores fields a client should not set', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        $other = Tenant::factory()->create();

        $this->actingAs($user)->post('/app/contracts', contractForm($customer, ['status' => 'settled', 'tenant_id' => $other->id, 'number' => 99, 'total' => '1.00']));

        $contract = asTenant($tenant, fn () => Contract::sole());
        expect($contract->status)->toBe('active')->and($contract->number)->toBe(1)->and($contract->tenant_id)->toBe($tenant->id)
            ->and(Money::cmp($contract->total, '1100'))->toBe(0);
    });

    it('stops at the plan limit with the upgrade sheet, creating nothing', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        foreach (range(1, 5) as $_) {
            openContract($tenant);
        }

        $this->actingAs($user)->from('/app/contracts/create')->post('/app/contracts', contractForm($customer))
            ->assertRedirect('/app/contracts/create')
            ->assertSessionHas('upgrade.code', 'limit_reached')
            ->assertSessionHas('upgrade.feature', 'active_contracts');
        expect(contractsIn($tenant))->toBe(5);
    });

    it('is refused to a viewer', function () {
        [, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs(memberAs('viewer', $tenant))->post('/app/contracts', contractForm($customer))->assertForbidden();
        expect(contractsIn($tenant))->toBe(0);
    });
});

describe('the live preview', function () {
    it('answers a signed-in person with the exact schedule', function () {
        [$user] = owner();

        $this->actingAs($user)->postJson(route('app.contracts.preview'), [
            'principal' => '100.00', 'down_payment' => '0', 'markup_type' => 'none', 'markup_value' => '0',
            'count' => 3, 'frequency' => 'monthly', 'first_due_date' => '2026-11-01',
        ])->assertOk()->assertJsonPath('total', '100.00')->assertJsonPath('installments.2.amount', '33.34');
    });

    it('is for signed-in people only', function () {
        $this->postJson('/app/contracts/preview', ['principal' => '100'])->assertUnauthorized();
    });

    it('refuses impossible plans with a message', function () {
        [$user] = owner();

        $this->actingAs($user)->postJson('/app/contracts/preview', ['principal' => '100', 'down_payment' => '100', 'count' => 3, 'frequency' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('down_payment');
    });
});

describe('a contract’s page', function () {
    it('shows each instalment as paid, partly paid or upcoming', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant, ['first_due_date' => '2026-10-05']); // 5 Oct, 5 Nov, 5 Dec
        app(RecordPayment::class)->handle($contract, '150.00', 'cash');

        $page = $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertOk();

        $page->assertSee($contract->reference())
            ->assertSeeInOrder(['5 Oct 2026', '5 Nov 2026', '5 Dec 2026'])
            ->assertSeeInOrder(['data-state="paid"', 'data-state="partial"', 'data-state="upcoming"'], false)
            ->assertSee(Format::money('150.00', 'SAR'));
    });

    it('marks an unpaid instalment past its date as overdue', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant, ['first_due_date' => '2026-09-01']); // 1 Sep, 1 Oct, 1 Nov

        $this->actingAs($user)->get(route('app.contracts.show', $contract))
            ->assertSeeInOrder(['data-state="overdue"', 'data-state="overdue"', 'data-state="upcoming"'], false);
    });

    it('shows the customer and links back to them', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->get(route('app.contracts.show', $contract))
            ->assertSee(customerNameOf($contract))->assertSee(route('app.customers.show', $contract->customer_id), false);
    });

    it('shows the terms: price, down payment, markup and the total to repay', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant, ['principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10', 'installment_count' => 4]);

        $this->actingAs($user)->get(route('app.contracts.show', $contract))
            ->assertSee(Format::money('1200.00', 'SAR'))->assertSee(Format::money('200.00', 'SAR'))
            ->assertSee(Format::money('100.00', 'SAR'))->assertSee(Format::money('1100.00', 'SAR'));
    });

    it('lists the money received, newest first, with who took it and how', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        $clerk = memberAs('collector', $tenant);
        $clerk->forceFill(['name' => 'Karim Collector'])->save();
        $this->travelTo('2026-10-08 12:00:00');
        app(RecordPayment::class)->handle($contract, '40.00', 'bank_transfer', by: $clerk, note: 'Transfer 8841');
        $this->travelTo('2026-10-09 12:00:00');
        app(RecordPayment::class)->handle($contract, '25.00', 'cash', by: $user);

        $this->actingAs($user)->get(route('app.contracts.show', $contract))
            ->assertSeeInOrder([Format::money('25.00', 'SAR'), Format::money('40.00', 'SAR')])
            ->assertSee('Transfer 8841')->assertSee('Karim Collector')->assertSee('Bank transfer');
    });

    it('offers a payment form while money is owed', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->get(route('app.contracts.show', $contract))
            ->assertSee('name="amount"', false)->assertSee('name="idempotency_key"', false)->assertSee(route('app.contracts.payments.store', $contract), false);
    });

    it('offers no payment form to a viewer, nor once everything is paid', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $settled = openContract($tenant);
        app(RecordPayment::class)->handle($settled, '300.00', 'cash');

        $this->actingAs(memberAs('viewer', $tenant))->get(route('app.contracts.show', $contract))->assertOk()->assertDontSee('name="amount"', false);
        $this->actingAs($user)->get(route('app.contracts.show', $settled))->assertOk()->assertDontSee('name="amount"', false)->assertSee('data-state="paid"', false);
    });

    it('shows a cancelled contract as cancelled and offers no payment form', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        asTenant($tenant, fn () => Contract::find($contract->id)->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save());

        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('data-status="cancelled"', false)->assertDontSee('name="amount"', false)->assertDontSee(route('app.contracts.cancel', $contract), false);
    });

    it('offers cancelling only to owners and managers', function (string $role, bool $offered) {
        [, $tenant] = owner();
        $contract = openContract($tenant);

        $page = $this->actingAs(memberAs($role, $tenant))->get(route('app.contracts.show', $contract))->assertOk();
        $offered ? $page->assertSee(route('app.contracts.cancel', $contract), false) : $page->assertDontSee(route('app.contracts.cancel', $contract), false);
    })->with([['owner', true], ['manager', true], ['accountant', false], ['collector', false], ['viewer', false]]);

    it('is not found in another workspace', function () {
        [$user] = owner();
        $theirs = openContract(Tenant::factory()->create());

        $this->actingAs($user)->get(route('app.contracts.show', $theirs))->assertNotFound();
    });
});

describe('cancelling', function () {
    it('cancels a running contract, frees its place on the plan and writes an audit entry', function () {
        [$user, $tenant] = owner();
        $contracts = array_map(fn () => openContract($tenant), range(1, 5));
        $customer = customerIn($tenant);
        $contract = $contracts[0];

        $this->actingAs($user)->post(route('app.contracts.cancel', $contract), ['reason' => 'Customer returned the item'])
            ->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('status');

        $fresh = asTenant($tenant, fn () => Contract::find($contract->id));
        expect($fresh->status)->toBe('cancelled')->and($fresh->cancelled_at)->not->toBeNull();
        $log = AuditLog::where('action', 'contract.cancelled')->sole();
        expect($log->user_id)->toBe($user->id)->and($log->subject_id)->toBe($contract->id)->and($log->changes['reason'])->toBe('Customer returned the item');

        // The place is free again: a sixth contract can be opened on a plan that allows five running ones.
        $this->actingAs($user)->post('/app/contracts', contractForm($customer))->assertSessionMissing('upgrade');
        expect(contractsIn($tenant))->toBe(6);
    });

    it('keeps its ledger entries', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        app(RecordPayment::class)->handle($contract, '100.00', 'cash');

        $this->actingAs($user)->post(route('app.contracts.cancel', $contract));

        expect(asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count()))->toBe(1);
    });

    it('is only for owners and managers', function (string $role, int $status) {
        [, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs(memberAs($role, $tenant))->post(route('app.contracts.cancel', $contract))->assertStatus($status);
        expect(asTenant($tenant, fn () => Contract::find($contract->id)->status))->toBe($status === 302 ? 'cancelled' : 'active');
    })->with([['owner', 302], ['manager', 302], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('cannot be done twice or to a settled contract', function () {
        [$user, $tenant] = owner();
        $cancelled = openContract($tenant);
        $settled = openContract($tenant);
        app(RecordPayment::class)->handle($settled, '300.00', 'cash');

        $this->actingAs($user)->post(route('app.contracts.cancel', $cancelled));
        $this->actingAs($user)->from('/x')->post(route('app.contracts.cancel', $cancelled))->assertSessionHasErrors('contract');
        $this->actingAs($user)->from('/x')->post(route('app.contracts.cancel', $settled))->assertSessionHasErrors('contract');
        expect(asTenant($tenant, fn () => Contract::find($settled->id)->status))->toBe('settled')
            ->and(AuditLog::where('action', 'contract.cancelled')->count())->toBe(1);
    });

    it('does not accept an over-long reason', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->from('/x')->post(route('app.contracts.cancel', $contract), ['reason' => str_repeat('a', 501)])->assertSessionHasErrors('reason');
        expect(asTenant($tenant, fn () => Contract::find($contract->id)->status))->toBe('active');
    });

    it('is not found in another workspace', function () {
        [$user] = owner();
        $theirs = openContract(Tenant::factory()->create());

        $this->actingAs($user)->post(route('app.contracts.cancel', $theirs))->assertNotFound();
    });
});
