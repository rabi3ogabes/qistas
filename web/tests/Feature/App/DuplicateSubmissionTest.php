<?php

use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| One press, one save. Every web form carries a one-time "_once" token (resources/js/forms.js adds it on submit). A form
| sent twice with the same token, by a double click, an impatient second tap or a browser retrying, is saved once: the
| repeat gets the first answer instead of making a second record.
*/

function customersSavedIn(Tenant $tenant): int
{
    return asTenant($tenant, fn () => Customer::count());
}

function addCustomer(User $user, ?string $once, string $name = 'Huda'): TestResponse
{
    $fields = ['name' => $name, 'phone' => '+966551112201'];

    return test()->actingAs($user)->post('/app/customers', $once === null ? $fields : $fields + ['_once' => $once]);
}

it('saves a form sent twice with the same token once, and answers the repeat like the first', function () {
    [$owner, $tenant] = owner();
    $once = (string) Str::uuid();

    $first = addCustomer($owner, $once)->assertRedirect();
    $repeat = addCustomer($owner, $once)->assertRedirect();

    expect(customersSavedIn($tenant))->toBe(1)
        ->and($repeat->headers->get('Location'))->toBe($first->headers->get('Location'));
});

it('saves each new press: a new token is a new save', function () {
    [$owner, $tenant] = owner();

    addCustomer($owner, (string) Str::uuid())->assertRedirect();
    addCustomer($owner, (string) Str::uuid(), 'Rana')->assertRedirect();

    expect(customersSavedIn($tenant))->toBe(2);
});

it('lets a form without a token through as before', function () {
    [$owner, $tenant] = owner();

    addCustomer($owner, null)->assertRedirect();
    addCustomer($owner, null, 'Rana')->assertRedirect();

    expect(customersSavedIn($tenant))->toBe(2);
});

it('lets the same press through again once it came back with mistakes to fix', function () {
    [$owner, $tenant] = owner();
    $once = (string) Str::uuid();

    $this->actingAs($owner)->post('/app/customers', ['name' => '', 'phone' => '+966551112201', '_once' => $once])->assertSessionHasErrors('name');
    addCustomer($owner, $once)->assertSessionHasNoErrors()->assertRedirect();

    expect(customersSavedIn($tenant))->toBe(1);
});

it('keeps one person’s token from stopping another person’s save', function () {
    [$owner, $tenant] = owner();
    $colleague = memberAs('manager', $tenant);
    $once = (string) Str::uuid();

    addCustomer($owner, $once)->assertRedirect();
    addCustomer($colleague, $once, 'Rana')->assertRedirect();

    expect(customersSavedIn($tenant))->toBe(2);
});

describe('from the app', function () {
    it('adds once when the app sends the same thing twice with one Idempotency-Key, answering the repeat like the first', function () {
        [, $tenant] = apiOwner();
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/v1/customers', ['name' => 'Huda', 'phone' => '+966551112201'], ['Idempotency-Key' => $key])->assertCreated();
        $repeat = $this->postJson('/api/v1/customers', ['name' => 'Huda', 'phone' => '+966551112201'], ['Idempotency-Key' => $key])->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        expect(customersSavedIn($tenant))->toBe(1)->and($repeat->json('data.id'))->toBe($first->json('data.id'));
    });

    it('guards every add: contracts, products and tags too', function (string $path, array $body) {
        [, $tenant] = apiOwner();
        switchOn(Feature::CustomerTags);
        switchOn(Feature::ContractItems);
        $key = (string) Str::uuid();
        $body = $path === '/api/v1/contracts' ? $body + ['customer_id' => customerIn($tenant)->id] : $body;

        $first = $this->postJson($path, $body, ['Idempotency-Key' => $key]);
        $repeat = $this->postJson($path, $body, ['Idempotency-Key' => $key]);

        expect($first->status())->toBe(201)->and($repeat->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($repeat->json('data.id'))->toBe($first->json('data.id'));
    })->with([
        'a contract' => ['/api/v1/contracts', ['principal' => '1200', 'installment_count' => 6, 'frequency' => 'monthly', 'first_due_date' => '2026-11-20']],
        'a product' => ['/api/v1/products', ['name' => 'iPhone 16']],
        'a tag' => ['/api/v1/tags', ['name' => 'Shop 2']],
    ]);

    it('lets the same key through again after mistakes, and keeps keys apart between people', function () {
        [$owner, $tenant] = apiOwner();
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/customers', ['name' => '', 'phone' => '+966551112201'], ['Idempotency-Key' => $key])->assertStatus(422);
        $this->postJson('/api/v1/customers', ['name' => 'Huda', 'phone' => '+966551112201'], ['Idempotency-Key' => $key])->assertCreated();

        Sanctum::actingAs(memberAs('manager', $tenant), ['app']);
        $this->postJson('/api/v1/customers', ['name' => 'Rana', 'phone' => '+966551112202'], ['Idempotency-Key' => $key])->assertCreated();

        expect(customersSavedIn($tenant))->toBe(2);
    });
});

it('ignores a token that is not one', function () {
    [$owner, $tenant] = owner();

    addCustomer($owner, 'x')->assertRedirect();
    addCustomer($owner, 'x', 'Rana')->assertRedirect();

    expect(customersSavedIn($tenant))->toBe(2);
});
