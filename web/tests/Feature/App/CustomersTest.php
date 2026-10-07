<?php

use App\Actions\RecordPayment;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

function customerPayload(array $overrides = []): array
{
    return array_merge(['name' => 'Layla Haddad', 'phone' => '+966 50 123 4567', 'email' => 'layla@example.com'], $overrides);
}

function customersIn(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Customer::count());
}

describe('the list', function () {
    it('sends guests to sign in', function () {
        $this->get('/app/customers')->assertRedirect(route('login'));
    });

    it('lists this workspace’s customers, alphabetically, and nobody else’s', function () {
        [$user, $tenant] = owner();
        customerIn($tenant, ['name' => 'Zainab']);
        customerIn($tenant, ['name' => 'Ahmad']);
        customerIn(Tenant::factory()->create(), ['name' => 'Someone Else']);

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSeeInOrder(['Ahmad', 'Zainab'])->assertDontSee('Someone Else');
    });

    it('shows what each customer owes and how many contracts are running', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant); // 300.00 over 3 instalments
        app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        $name = asTenant($tenant, fn () => $contract->customer->name);

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSee($name)->assertSee(Format::money('200.00', 'SAR'));
    });

    it('finds customers by name, phone or email', function (string $term, string $expected, string $other) {
        [$user, $tenant] = owner();
        customerIn($tenant, ['name' => 'Layla Haddad', 'phone' => '+966501234567', 'email' => 'layla@example.com']);
        customerIn($tenant, ['name' => 'Omar Khalil', 'phone' => '+971559876543', 'email' => null]);

        $this->actingAs($user)->get('/app/customers?q='.urlencode($term))->assertOk()->assertSee($expected)->assertDontSee($other);
    })->with([
        'name' => ['khal', 'Omar Khalil', 'Layla Haddad'],
        'phone' => ['501234567', 'Layla Haddad', 'Omar Khalil'],
        'email' => ['layla@', 'Layla Haddad', 'Omar Khalil'],
        'arabic digits' => ['٩٧١٥٥٩٨٧٦٥٤٣', 'Omar Khalil', 'Layla Haddad'],
    ]);

    it('says when a search finds nothing, and how to clear it', function () {
        [$user, $tenant] = owner();
        customerIn($tenant, ['name' => 'Layla Haddad']);

        $this->actingAs($user)->get('/app/customers?q=zzz')->assertOk()
            ->assertSee('No customers match')->assertSee('zzz')->assertSee(route('app.customers.index'), false);
    });

    it('invites you to add the first customer', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSee('No customers yet')->assertSee(route('app.customers.create'), false);
    });

    it('pages through a long list and keeps the search', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        foreach (range(1, 45) as $i) {
            customerIn($tenant, ['name' => sprintf('Customer %02d', $i)]);
        }

        $first = $this->actingAs($user)->get('/app/customers');
        $first->assertSee('Customer 01')->assertSee('Customer 20')->assertDontSee('Customer 21')->assertSee('page=2', false);
        $this->actingAs($user)->get('/app/customers?page=3')->assertSee('Customer 45')->assertDontSee('Customer 20');
        $this->actingAs($user)->get('/app/customers?q=Customer&page=2')->assertSee('q=Customer', false);
    });

    it('does not show deleted customers', function () {
        [$user, $tenant] = owner();
        $gone = customerIn($tenant, ['name' => 'Gone Away']);
        asTenant($tenant, fn () => $gone->delete());

        $this->actingAs($user)->get('/app/customers')->assertDontSee('Gone Away');
    });

    it('lets a viewer look but not add', function () {
        [, $tenant] = owner();
        $viewer = memberAs('viewer', $tenant);
        customerIn($tenant, ['name' => 'Layla Haddad']);

        $this->actingAs($viewer)->get('/app/customers')->assertOk()->assertSee('Layla Haddad')->assertDontSee(route('app.customers.create'), false);
    });
});

describe('adding a customer', function () {
    it('shows the form with the plan usage', function () {
        [$user, $tenant] = owner();
        customerIn($tenant);

        $this->actingAs($user)->get('/app/customers/create')->assertOk()
            ->assertSee('name="name"', false)->assertSee('name="phone"', false)->assertSee('name="national_id"', false)
            ->assertSee('1 of 5');
    });

    it('adds the customer and opens their page', function () {
        [$user, $tenant] = owner();

        $response = $this->actingAs($user)->post('/app/customers', customerPayload(['national_id' => '1098765432', 'address' => 'Riyadh', 'notes' => 'Prefers WhatsApp']));

        $customer = asTenant($tenant, fn () => Customer::sole());
        $response->assertRedirect(route('app.customers.show', $customer))->assertSessionHas('status');
        expect($customer->name)->toBe('Layla Haddad')->and($customer->created_by_user_id)->toBe($user->id)
            ->and($customer->national_id)->toBe('1098765432')->and($customer->tenant_id)->toBe($tenant->id);
    });

    it('reads a phone number typed on an Arabic keyboard', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->post('/app/customers', customerPayload(['phone' => '٠٥٠ ١٢٣ ٤٥٦٧']));

        expect(asTenant($tenant, fn () => Customer::sole()->phone))->toBe('050 123 4567');
    });

    it('explains what is wrong and keeps what was typed', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->from('/app/customers/create')->post('/app/customers', customerPayload(['name' => '', 'phone' => 'call me']))
            ->assertRedirect('/app/customers/create')->assertSessionHasErrors(['name', 'phone']);
        expect(customersIn($tenant))->toBe(0);

        $this->actingAs($user)->get('/app/customers/create')->assertOk();
    });

    it('ignores fields a client should not be able to set', function () {
        [$user, $tenant] = owner();
        $other = Tenant::factory()->create();

        $this->actingAs($user)->post('/app/customers', customerPayload(['tenant_id' => $other->id, 'created_by_user_id' => 'x', 'id' => 'y']));

        $customer = asTenant($tenant, fn () => Customer::sole());
        expect($customer->tenant_id)->toBe($tenant->id)->and(customersIn($other))->toBe(0);
    });

    it('stops at the plan limit with the upgrade sheet, creating nothing', function () {
        [$user, $tenant] = owner();
        foreach (range(1, 5) as $_) {
            customerIn($tenant);
        }

        $this->actingAs($user)->from('/app/customers/create')->post('/app/customers', customerPayload())
            ->assertRedirect('/app/customers/create')
            ->assertSessionHas('upgrade.code', 'limit_reached')
            ->assertSessionHas('upgrade.feature', 'customers')
            ->assertSessionHas('upgrade.limit', 5);
        expect(customersIn($tenant))->toBe(5);
    });

    it('offers the upgrade instead of the form when the limit is already reached', function () {
        [$user, $tenant] = owner();
        foreach (range(1, 5) as $_) {
            customerIn($tenant);
        }

        $this->actingAs($user)->get('/app/customers/create')->assertOk()
            ->assertSee(url('/app/billing'), false)->assertDontSee('name="phone"', false);
    });

    it('never stops a Pro workspace', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        foreach (range(1, 7) as $_) {
            customerIn($tenant);
        }

        $this->actingAs($user)->post('/app/customers', customerPayload())->assertSessionHasNoErrors()->assertSessionMissing('upgrade');
        expect(customersIn($tenant))->toBe(8);
    });

    it('refuses a viewer', function () {
        [, $tenant] = owner();

        $this->actingAs(memberAs('viewer', $tenant))->get('/app/customers/create')->assertForbidden();
        $this->actingAs(memberAs('viewer', $tenant))->post('/app/customers', customerPayload())->assertForbidden();
        expect(customersIn($tenant))->toBe(0);
    });
});

describe('a customer’s page', function () {
    it('shows their details with call and message links', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['name' => 'Layla Haddad', 'phone' => '+966 50 123 4567', 'email' => 'layla@example.com', 'address' => 'Riyadh, Olaya', 'notes' => 'Prefers WhatsApp']);

        $this->actingAs($user)->get(route('app.customers.show', $customer))->assertOk()
            ->assertSee('Layla Haddad')->assertSee('+966 50 123 4567')->assertSee('layla@example.com')->assertSee('Riyadh, Olaya')->assertSee('Prefers WhatsApp')
            ->assertSee('href="tel:+966501234567"', false)
            ->assertSee('href="https://wa.me/966501234567"', false);
    });

    it('never prints the national ID in full', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['national_id' => '1098765432']);

        $this->actingAs($user)->get(route('app.customers.show', $customer))->assertOk()
            ->assertDontSee('1098765432')->assertSee('••••••432');
    });

    it('lists their contracts with what is still owed', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSee($contract->reference())->assertSee(Format::money('300.00', 'SAR'))
            ->assertSee(url('/app/contracts/'.$contract->id), false);
    });

    it('offers to open a contract for them', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->get(route('app.customers.show', $customer))->assertSee(url('/app/contracts/create').'?customer='.$customer->id, false);
    });

    it('is not found in another workspace, or once deleted', function () {
        [$user, $tenant] = owner();
        $theirs = customerIn(Tenant::factory()->create());
        $deleted = customerIn($tenant);
        asTenant($tenant, fn () => $deleted->delete());

        $this->actingAs($user)->get(route('app.customers.show', $theirs))->assertNotFound();
        $this->actingAs($user)->get(route('app.customers.show', $deleted))->assertNotFound();
    });
});

describe('editing', function () {
    it('shows the form filled in, with the national ID hidden', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['name' => 'Layla Haddad', 'national_id' => '1098765432']);

        $this->actingAs($user)->get(route('app.customers.edit', $customer))->assertOk()
            ->assertSee('value="Layla Haddad"', false)->assertDontSee('1098765432')->assertSee('••••••432');
    });

    it('saves changes', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['name' => 'Layla Haddad']);

        $this->actingAs($user)->put(route('app.customers.update', $customer), customerPayload(['name' => 'Layla H.', 'notes' => 'Moved']))
            ->assertRedirect(route('app.customers.show', $customer))->assertSessionHas('status');

        $fresh = asTenant($tenant, fn () => Customer::find($customer->id));
        expect($fresh->name)->toBe('Layla H.')->and($fresh->notes)->toBe('Moved');
    });

    it('keeps the stored national ID when the field is left blank, and replaces it when filled', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['national_id' => '1098765432']);

        $this->actingAs($user)->put(route('app.customers.update', $customer), customerPayload(['national_id' => '']));
        expect(asTenant($tenant, fn () => Customer::find($customer->id)->national_id))->toBe('1098765432');

        $this->actingAs($user)->put(route('app.customers.update', $customer), customerPayload(['national_id' => '2000000001']));
        expect(asTenant($tenant, fn () => Customer::find($customer->id)->national_id))->toBe('2000000001');
    });

    it('can remove the stored national ID on request', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['national_id' => '1098765432']);

        $this->actingAs($user)->put(route('app.customers.update', $customer), customerPayload(['remove_national_id' => '1']));

        expect(asTenant($tenant, fn () => Customer::find($customer->id)->national_id))->toBeNull();
    });

    it('rejects bad input and changes nothing', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['name' => 'Layla Haddad']);

        $this->actingAs($user)->from('/x')->put(route('app.customers.update', $customer), customerPayload(['name' => '']))->assertSessionHasErrors('name');

        expect(asTenant($tenant, fn () => Customer::find($customer->id)->name))->toBe('Layla Haddad');
    });

    it('does not let someone move a customer to another workspace', function () {
        [$user, $tenant] = owner();
        $other = Tenant::factory()->create();
        $customer = customerIn($tenant);

        $this->actingAs($user)->put(route('app.customers.update', $customer), customerPayload(['tenant_id' => $other->id]));

        expect(asTenant($tenant, fn () => Customer::find($customer->id))->tenant_id)->toBe($tenant->id);
    });

    it('is refused to a viewer and not found across workspaces', function () {
        [, $tenant] = owner();
        $customer = customerIn($tenant);
        $theirs = customerIn(Tenant::factory()->create());
        [$mine] = owner();

        $this->actingAs(memberAs('viewer', $tenant))->put(route('app.customers.update', $customer), customerPayload())->assertForbidden();
        $this->actingAs($mine)->put(route('app.customers.update', $theirs), customerPayload())->assertNotFound();
        $this->actingAs($mine)->get(route('app.customers.edit', $theirs))->assertNotFound();
    });
});

describe('deleting', function () {
    it('removes a customer and frees a place on the plan', function () {
        [$user, $tenant] = owner();
        foreach (range(1, 4) as $_) {
            customerIn($tenant);
        }
        $customer = customerIn($tenant);

        $this->actingAs($user)->delete(route('app.customers.destroy', $customer))
            ->assertRedirect(route('app.customers.index'))->assertSessionHas('status');

        expect(customersIn($tenant))->toBe(4);
        $this->actingAs($user)->post('/app/customers', customerPayload())->assertSessionMissing('upgrade');
        expect(customersIn($tenant))->toBe(5);
    });

    it('refuses while the customer has a running contract', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->from('/x')->delete(route('app.customers.destroy', $contract->customer_id))
            ->assertRedirect('/x')->assertSessionHasErrors('customer');

        expect(customersIn($tenant))->toBe(1);
    });

    it('allows it once their contracts are settled or cancelled', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        app(RecordPayment::class)->handle($contract, '300.00', 'cash');

        $this->actingAs($user)->delete(route('app.customers.destroy', $contract->customer_id))->assertSessionHasNoErrors();

        expect(customersIn($tenant))->toBe(0);
    });

    it('keeps their contracts and payments in the books', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        app(RecordPayment::class)->handle($contract, '300.00', 'cash');

        $this->actingAs($user)->delete(route('app.customers.destroy', $contract->customer_id));

        expect(DB::table('contracts')->where('id', $contract->id)->exists())->toBeTrue()
            ->and(DB::table('transactions')->where('contract_id', $contract->id)->exists())->toBeTrue();
    });

    it('is only for owners and managers', function (string $role, int $status) {
        [, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs(memberAs($role, $tenant))->delete(route('app.customers.destroy', $customer))->assertStatus($status);
    })->with([['owner', 302], ['manager', 302], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('is not found in another workspace', function () {
        [$user] = owner();
        $theirs = customerIn(Tenant::factory()->create());

        $this->actingAs($user)->delete(route('app.customers.destroy', $theirs))->assertNotFound();
        expect(DB::table('customers')->where('id', $theirs->id)->whereNull('deleted_at')->exists())->toBeTrue();
    });
});
