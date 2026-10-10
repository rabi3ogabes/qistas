<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| Win Plan PP10: an activity log for owners and managers. Who added, changed, recorded, voided or removed what, newest
| first, filtered by person, by kind and by date. A business sees only its own; nothing personal is kept in it beyond
| a customer's name and the numbers of what was done.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

/** @return array{0: User, 1: Tenant, 2: User} an owner, their business and a collector who works there */
function busyBusiness(): array
{
    [$owner, $tenant] = apiOwner();
    $owner->forceFill(['name' => 'Layla Haddad'])->save();
    $collector = memberAs('collector', $tenant);
    $collector->forceFill(['name' => 'Omar Collector'])->save();

    return [$owner, $tenant, $collector];
}

/**
 * The entries, with the non-breaking space money is written with made plain, so they read as typed here.
 *
 * @return list<array<string, mixed>>
 */
function activity(array $query = []): array
{
    $entries = test()->getJson('/api/v1/activity?'.http_build_query($query))->assertOk()->json('data');

    return array_map(fn (array $entry) => ['summary' => str_replace("\u{00A0}", ' ', $entry['summary'])] + $entry, $entries);
}

describe('what is recorded', function () {
    it('records customers added, changed and removed, from the web or the app', function () {
        [$owner] = busyBusiness();

        $id = $this->postJson('/api/v1/customers', ['name' => 'Ahmad Salem', 'phone' => '+966551234567'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/customers/{$id}", ['name' => 'Ahmad Salem', 'phone' => '+966551234567', 'email' => 'ahmad@example.test', 'national_id' => '1234567890'])->assertOk();
        $this->deleteJson("/api/v1/customers/{$id}")->assertNoContent();

        $entries = activity();
        expect(array_column($entries, 'action'))->toBe(['customer.deleted', 'customer.updated', 'customer.created'])
            ->and($entries[2]['summary'])->toBe('Customer added: Ahmad Salem')
            ->and($entries[2]['person']['name'])->toBe('Layla Haddad')
            ->and($entries[1]['summary'])->toBe('Customer changed: Ahmad Salem (email, national ID)');
    });

    it('never keeps the national ID or other details, only which ones changed', function () {
        busyBusiness();
        $id = $this->postJson('/api/v1/customers', ['name' => 'Ahmad Salem', 'phone' => '+966551234567'])->json('data.id');

        $this->putJson("/api/v1/customers/{$id}", ['name' => 'Ahmad Salem', 'phone' => '+966557654321', 'email' => 'ahmad@example.test', 'national_id' => '1234567890'])->assertOk();

        $changed = AuditLog::where('action', 'customer.updated')->sole()->changes;
        expect($changed)->toBe(['name' => 'Ahmad Salem', 'fields' => ['phone', 'email', 'national_id']])
            ->and(json_encode(AuditLog::all()->pluck('changes')))->not->toContain('1234567890')->not->toContain('ahmad@example.test')->not->toContain('557654321');
    });

    it('records contracts opened, payments recorded and payments voided, with the person who did each', function () {
        [$owner, $tenant, $collector] = busyBusiness();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '150.00', 'cash', by: $collector);
        app(VoidTransaction::class)->handle($payment, 'Entered twice', $owner);

        $entries = activity(['kind' => 'payments']);

        expect(array_column($entries, 'summary'))->toBe(['Payment voided: SAR 150.00 on C-0001', 'Payment recorded: SAR 150.00 on C-0001'])
            ->and($entries[1]['person']['name'])->toBe('Omar Collector')
            ->and($entries[0]['person']['name'])->toBe('Layla Haddad')
            ->and(array_column(activity(['kind' => 'contracts']), 'summary'))->toBe(['Contract opened: C-0001']);
    });
});

describe('reading it', function () {
    it('filters by person and by date', function () {
        [$owner, $tenant, $collector] = busyBusiness();
        customerIn($tenant, ['name' => 'Early Customer']);
        $contract = openContract($tenant);
        $this->travelTo('2026-10-15 10:00:00');
        app(RecordPayment::class)->handle($contract, '50.00', 'cash', by: $collector);
        apiOwner(); // someone else's business, busy too
        customerIn(Tenant::latest('created_at')->first(), ['name' => 'Next Door']);
        Sanctum::actingAs($owner, ['app']);

        expect(array_column(activity(['user' => $collector->id]), 'summary'))->toBe(['Payment recorded: SAR 50.00 on C-0001'])
            ->and(count(activity(['from' => '2026-10-12'])))->toBe(1)
            ->and(count(activity(['to' => '2026-10-11'])))->toBeGreaterThanOrEqual(2)
            ->and(collect(activity())->pluck('summary')->implode(' '))->not->toContain('Next Door');
    });

    it('lists the people to filter by', function () {
        [$owner, , $collector] = busyBusiness();

        $this->getJson('/api/v1/activity')->assertOk()
            ->assertJsonPath('meta.people', fn (array $people) => collect($people)->pluck('name')->sort()->values()->all() === ['Layla Haddad', 'Omar Collector']);
    });

    it('is for owners and managers only', function (string $role, int $status) {
        [, $tenant] = busyBusiness();
        apiMember($role, $tenant);

        $this->getJson('/api/v1/activity')->assertStatus($status);
    })->with([['manager', 200], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('has its own page in the web app', function () {
        [$owner, $tenant] = busyBusiness();
        customerIn($tenant, ['name' => 'Ahmad Salem']);

        $this->actingAs($owner)->get(route('app.activity'))->assertOk()->assertSee('Customer added: Ahmad Salem')->assertSee('Layla Haddad');
        $this->actingAs(memberAs('collector', $tenant))->get(route('app.activity'))->assertForbidden();
    });
});
