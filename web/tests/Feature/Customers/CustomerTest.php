<?php

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function customerIn(Tenant $tenant, array $attributes = []): Customer
{
    return asTenant($tenant, fn () => Customer::factory()->create($attributes));
}

it('keeps the national ID encrypted at rest and readable through the model', function () {
    $customer = customerIn(Tenant::factory()->create(), ['national_id' => '1098765432']);

    $raw = DB::table('customers')->where('id', $customer->id)->value('national_id');

    expect($raw)->not->toBe('1098765432')->and($raw)->not->toContain('1098765432')
        ->and(asTenant($customer->tenant, fn () => Customer::find($customer->id)->national_id))->toBe('1098765432');
});

it('allows a customer without a national ID', function () {
    $customer = customerIn(Tenant::factory()->create(), ['national_id' => null]);

    expect(DB::table('customers')->where('id', $customer->id)->value('national_id'))->toBeNull();
});

it('never serialises the national ID into JSON by accident', function () {
    $customer = customerIn(Tenant::factory()->create(), ['national_id' => '1098765432']);

    expect($customer->toJson())->not->toContain('1098765432')->not->toContain('national_id');
});

it('only shows a workspace its own customers', function () {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    customerIn($a, ['name' => 'Alpha']);
    customerIn($b, ['name' => 'Bravo']);

    expect(asTenant($a, fn () => Customer::pluck('name')->all()))->toBe(['Alpha'])
        ->and(asTenant($b, fn () => Customer::pluck('name')->all()))->toBe(['Bravo'])
        ->and(Customer::withoutGlobalScope(TenantScope::class)->count())->toBe(2);
});

it('answers 404 when someone asks for another workspace’s customer by id', function () {
    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/customers/{customer}', fn (Customer $customer) => $customer->name);
    [$owner, $mine] = makeAccount();
    $other = customerIn(Tenant::factory()->create(), ['name' => 'Not yours']);
    $own = customerIn($mine, ['name' => 'Yours']);

    $this->actingAs($owner)->get("/_test/customers/{$own->id}")->assertOk()->assertSee('Yours');
    $this->actingAs($owner)->get("/_test/customers/{$other->id}")->assertNotFound();
});

describe('searching', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create();
        customerIn($this->tenant, ['name' => 'Layla Haddad', 'phone' => '+966501234567', 'email' => 'layla@example.com']);
        customerIn($this->tenant, ['name' => 'Omar Khalil', 'phone' => '+971559876543', 'phone_secondary' => '+971501112222', 'email' => null]);
        customerIn($this->tenant, ['name' => 'ليلى الفارس', 'phone' => '+201001234567', 'email' => null]);
        customerIn(Tenant::factory()->create(), ['name' => 'Layla Elsewhere', 'phone' => '+966501234567']);
    });

    it('finds by name, ignoring case, in any script', function (string $term, array $expected) {
        expect(asTenant($this->tenant, fn () => Customer::search($term)->orderBy('name')->pluck('name')->all()))->toBe($expected);
    })->with([
        'latin, lower case' => ['layla', ['Layla Haddad']],
        'part of a name' => ['KHAL', ['Omar Khalil']],
        'arabic' => ['الفارس', ['ليلى الفارس']],
    ]);

    it('finds by either phone number or by email', function (string $term, string $expected) {
        expect(asTenant($this->tenant, fn () => Customer::search($term)->pluck('name')->all()))->toBe([$expected]);
    })->with([
        'primary phone' => ['501234567', 'Layla Haddad'],
        'secondary phone' => ['1112222', 'Omar Khalil'],
        'email' => ['layla@example', 'Layla Haddad'],
    ]);

    it('returns the whole list for a blank search', function () {
        expect(asTenant($this->tenant, fn () => Customer::search('   ')->count()))->toBe(3);
    });

    it('never leaks another workspace’s customers into the results', function () {
        expect(asTenant($this->tenant, fn () => Customer::search('Elsewhere')->count()))->toBe(0);
    });

    it('matches phone numbers typed with Arabic-Indic digits', function () {
        expect(asTenant($this->tenant, fn () => Customer::search('٥٠١٢٣٤٥٦٧')->pluck('name')->all()))->toBe(['Layla Haddad']);
    });
});

it('records who created the customer', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $customer = asTenant($tenant, function () use ($user) {
        $customer = Customer::factory()->make();
        $customer->created_by_user_id = $user->id;
        $customer->save();

        return $customer;
    });

    expect($customer->fresh()->created_by_user_id)->toBe($user->id);
});

it('does not let a client set the owner or the creator through mass assignment', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => asTenant($tenant, fn () => Customer::create([
        'name' => 'X', 'phone' => '+966501234567', 'created_by_user_id' => 'someone-else',
    ])))->toThrow(MassAssignmentException::class);
});

it('keeps deleted customers out of the lists but in the database', function () {
    $tenant = Tenant::factory()->create();
    $customer = customerIn($tenant);

    asTenant($tenant, fn () => $customer->delete());

    expect(asTenant($tenant, fn () => Customer::count()))->toBe(0)
        ->and(asTenant($tenant, fn () => Customer::onlyTrashed()->count()))->toBe(1);
});
