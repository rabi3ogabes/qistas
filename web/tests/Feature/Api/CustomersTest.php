<?php

use App\Actions\RecordPayment;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;

function customerBody(array $overrides = []): array
{
    return array_merge(['name' => 'Layla Haddad', 'phone' => '+966 50 123 4567', 'email' => 'layla@example.com'], $overrides);
}

function apiCustomersOf(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Customer::count());
}

describe('listing', function () {
    it('needs a token', function () {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    });

    it('lists this workspace’s customers alphabetically, with what each owes', function () {
        [, $tenant] = apiOwner(['currency' => 'SAR']);
        customerIn($tenant, ['name' => 'Zainab']);
        $ahmad = customerIn($tenant, ['name' => 'Ahmad']);
        customerIn(Tenant::factory()->create(), ['name' => 'Someone Else']);
        $contract = openContract($tenant, ['customer_id' => $ahmad->id]);
        app(RecordPayment::class)->handle($contract, '100.00', 'cash');

        $response = $this->getJson('/api/v1/customers')->assertOk();

        expect(array_column($response->json('data'), 'name'))->toBe(['Ahmad', 'Zainab'])
            ->and($response->json('data.0.owed'))->toBe('200.00')
            ->and($response->json('data.0.running_contracts'))->toBe(1)
            ->and($response->json('data.1.owed'))->toBe('0.00')
            ->and($response->json('meta.total'))->toBe(2);
    });

    it('finds customers by name, phone or e-mail', function (string $term) {
        [, $tenant] = apiOwner();
        customerIn($tenant, ['name' => 'Layla Haddad', 'phone' => '+966501234567', 'email' => 'layla@example.com']);
        customerIn($tenant, ['name' => 'Omar Khalil', 'phone' => '+966559990000', 'email' => 'omar@example.com']);

        $names = array_column($this->getJson('/api/v1/customers?q='.urlencode($term))->assertOk()->json('data'), 'name');

        expect($names)->toBe(['Layla Haddad']);
    })->with(['name' => ['haddad'], 'phone' => ['501234'], 'e-mail' => ['layla@']]);

    it('pages through a long list', function () {
        [, $tenant] = apiOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        foreach (range(1, 12) as $i) {
            customerIn($tenant, ['name' => sprintf('Customer %02d', $i)]);
        }

        $first = $this->getJson('/api/v1/customers?per_page=5')->assertOk();
        $third = $this->getJson('/api/v1/customers?per_page=5&page=3')->assertOk();

        expect($first->json('data'))->toHaveCount(5)->and($first->json('meta.last_page'))->toBe(3)->and($first->json('links.next'))->not->toBeNull()
            ->and(array_column($third->json('data'), 'name'))->toBe(['Customer 11', 'Customer 12']);
    });

    it('never gives a page larger than a hundred', function () {
        apiOwner();

        $this->getJson('/api/v1/customers?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/customers?per_page=abc')->assertOk()->assertJsonPath('meta.per_page', 20);
    });
});

describe('adding', function () {
    it('creates the customer and returns it', function () {
        [$user, $tenant] = apiOwner();

        $response = $this->postJson('/api/v1/customers', customerBody(['national_id' => '1234567890']))->assertCreated();

        $customer = asTenant($tenant, fn () => Customer::sole());
        $response->assertJsonPath('data.id', $customer->id)->assertJsonPath('data.name', 'Layla Haddad')->assertJsonPath('data.national_id', '••••••890');
        expect($customer->created_by_user_id)->toBe($user->id)->and($response->getContent())->not->toContain('1234567890');
    });

    it('explains what is wrong in the standard shape', function (array $changes, string $field) {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/customers', customerBody($changes))
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonValidationErrors($field, 'error.fields');
        expect(apiCustomersOf($tenant))->toBe(0);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'no phone' => [['phone' => ''], 'phone'],
        'a bad e-mail' => [['email' => 'nope'], 'email'],
        'a very long note' => [['notes' => str_repeat('n', 5001)], 'notes'],
    ]);

    it('reads Arabic-Indic digits in a phone number', function () {
        [, $tenant] = apiOwner();

        $this->postJson('/api/v1/customers', customerBody(['phone' => '٠٥٠١٢٣٤٥٦٧']))->assertCreated();

        expect(asTenant($tenant, fn () => Customer::sole()->phone))->toBe('0501234567');
    });

    it('stops at the plan limit with a 402 the app can act on', function () {
        [, $tenant] = apiOwner();
        foreach (range(1, 5) as $_) {
            customerIn($tenant);
        }

        $this->postJson('/api/v1/customers', customerBody())->assertStatus(402)->assertExactJson(['error' => [
            'code' => 'limit_reached',
            'message' => 'You have reached the limit of 5 customers on your plan.',
            'feature' => 'customers',
            'limit' => 5,
            'used' => 5,
            'upgrade_url' => url('/app/billing'),
        ]]);
        expect(apiCustomersOf($tenant))->toBe(5);
    });

    it('ignores fields a client should not set', function () {
        [, $tenant] = apiOwner();
        $other = Tenant::factory()->create();

        $this->postJson('/api/v1/customers', customerBody(['tenant_id' => $other->id, 'id' => 'x', 'created_by_user_id' => 'x']))->assertCreated();

        expect(asTenant($tenant, fn () => Customer::sole()->tenant_id))->toBe($tenant->id)->and(apiCustomersOf($other))->toBe(0);
    });

    it('is refused to a viewer', function () {
        [, $tenant] = owner();
        apiMember('viewer', $tenant);

        $this->postJson('/api/v1/customers', customerBody())->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        expect(apiCustomersOf($tenant))->toBe(0);
    });
});

describe('one customer', function () {
    it('is shown with its contracts', function () {
        [, $tenant] = apiOwner(['currency' => 'SAR']);
        $customer = customerIn($tenant, ['name' => 'Layla Haddad', 'phone' => '+966501234567']);
        $contract = openContract($tenant, ['customer_id' => $customer->id]);

        $this->getJson("/api/v1/customers/{$customer->id}")->assertOk()
            ->assertJsonPath('data.name', 'Layla Haddad')
            ->assertJsonPath('data.contracts.0.id', $contract->id)
            ->assertJsonPath('data.contracts.0.reference', $contract->reference())
            ->assertJsonPath('data.owed', '300.00');
    });

    it('can be changed', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant, ['name' => 'Old Name', 'national_id' => '555666777']);

        $this->putJson("/api/v1/customers/{$customer->id}", customerBody(['name' => 'New Name']))->assertOk()->assertJsonPath('data.name', 'New Name');

        $fresh = asTenant($tenant, fn () => Customer::find($customer->id));
        expect($fresh->name)->toBe('New Name')->and($fresh->national_id)->toBe('555666777'); // left alone when not sent
    });

    it('can have its national ID replaced or removed', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant, ['national_id' => '555666777']);

        $this->putJson("/api/v1/customers/{$customer->id}", customerBody(['national_id' => '999888777']))->assertOk()->assertJsonPath('data.national_id', '••••••777');
        $this->putJson("/api/v1/customers/{$customer->id}", customerBody(['remove_national_id' => true]))->assertOk()->assertJsonPath('data.national_id', null);

        expect(asTenant($tenant, fn () => Customer::find($customer->id)->national_id))->toBeNull();
    });

    it('can be deleted, which frees a place, while its contracts stay readable', function () {
        [, $tenant] = apiOwner();
        $customer = customerIn($tenant);
        $contract = openContract($tenant, ['customer_id' => $customer->id]);

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertUnprocessable()->assertJsonValidationErrors('customer', 'error.fields');
        app(RecordPayment::class)->handle($contract, '300.00', 'cash'); // settled: no longer running

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNoContent();

        $this->getJson("/api/v1/customers/{$customer->id}")->assertNotFound();
        $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()->assertJsonPath('data.customer.id', $customer->id);
        expect(apiCustomersOf($tenant))->toBe(0);
    });

    it('is a 404, never a 403, when it belongs to another workspace', function (string $method) {
        apiOwner();
        $theirs = customerIn(Tenant::factory()->create());

        $this->json($method, "/api/v1/customers/{$theirs->id}", $method === 'PUT' ? customerBody() : [])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    })->with(['GET', 'PUT', 'DELETE']);

    it('is refused to a viewer for changes, allowed for reading', function () {
        [, $tenant] = owner();
        $customer = customerIn($tenant);
        apiMember('viewer', $tenant);

        $this->getJson("/api/v1/customers/{$customer->id}")->assertOk();
        $this->putJson("/api/v1/customers/{$customer->id}", customerBody())->assertForbidden();
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertForbidden();
    });

    it('is not found with an id that is not an id', function () {
        apiOwner();

        $this->getJson('/api/v1/customers/not-a-uuid')->assertNotFound();
    });
});
