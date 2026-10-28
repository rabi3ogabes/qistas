<?php

use App\Actions\RecordPayment;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

// The account behind the customer's screen in the apps: the same lines, running balance and totals as the web page.

beforeEach(function () {
    // The default contract: 300.00 over 3 monthly instalments, due 1 Feb, 1 Mar and 1 Apr 2026.
    $this->travelTo('2026-03-10 12:00:00');
});

it('sends guests away', function () {
    $contract = openContract(Tenant::factory()->create());

    $this->getJson('/api/v1/contracts/'.$contract->id.'/account')->assertUnauthorized();
});

it('gives the lines with a running balance, the unpaid instalment where it fell due, and the totals', function () {
    [, $tenant] = apiOwner();
    $contract = openContract($tenant);
    asTenant($tenant, fn () => app(RecordPayment::class)->handle($contract, '100.00', 'cash', paidAt: Carbon::parse('2026-02-03 12:00:00'), tag: 'advance'));
    $march = installmentsOfContract($contract)->firstWhere('number', 2);

    $data = $this->getJson('/api/v1/contracts/'.$contract->id.'/account')->assertOk()->json('data');

    expect($data['contract_id'])->toBe($contract->id)
        ->and($data['open'])->toBeFalse()
        ->and($data['takes_payments'])->toBeTrue()
        ->and($data['next_installment_id'])->toBe($march->id)
        ->and(array_column($data['lines'], 'kind'))->toBe(['sale', 'payment', 'unpaid'])
        ->and($data['lines'][0])->toMatchArray(['date' => '2026-01-15', 'added' => '300.00', 'paid' => null, 'balance' => '300.00'])
        ->and($data['lines'][1])->toMatchArray(['date' => '2026-02-03', 'added' => null, 'paid' => '100.00', 'balance' => '200.00', 'method' => 'cash', 'tag' => 'advance'])
        ->and($data['lines'][2])->toMatchArray(['date' => '2026-03-01', 'balance' => null, 'installment' => ['id' => $march->id, 'number' => 2, 'due_date' => '2026-03-01', 'remaining' => '100.00']])
        ->and($data['totals'])->toBe(['instalment' => '100.00', 'paid_count' => 1, 'left_count' => 2, 'paid' => '100.00', 'left' => '200.00', 'late' => '100.00']);
});

it('is not found in another workspace', function () {
    apiOwner();
    $theirs = openContract(Tenant::factory()->create());

    $this->getJson('/api/v1/contracts/'.$theirs->id.'/account')->assertNotFound();
});
