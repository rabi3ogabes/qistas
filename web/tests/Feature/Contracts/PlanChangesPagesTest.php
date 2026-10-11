<?php

use App\Actions\CreateContract;
use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;

/*
| Win Plan PP6 on the web: settle early with the payoff in front of you, change the plan with the old and new schedules
| side by side, choose what a payment covers, and take one payment across a customer's contracts.
*/

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    foreach ([Feature::EarlySettlement, Feature::Reschedule, Feature::PaymentTargeting] as $feature) {
        switchOn($feature);
    }
});

/** @return array{0: User, 1: Tenant, 2: Contract} */
function onTheWeb(): array
{
    [$owner, $tenant] = owner();
    $contract = openContract($tenant, [
        'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
        'installment_count' => 4, 'start_date' => '2026-10-01', 'first_due_date' => '2026-11-01',
    ]);
    app(RecordPayment::class)->handle($contract, '275.00', 'cash');

    return [$owner, $tenant, $contract];
}

it('offers the three changes on a running contract, to an owner', function () {
    [$owner, , $contract] = onTheWeb();

    $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
        ->assertSee(route('app.contracts.settle.show', $contract), false)
        ->assertSee(route('app.contracts.plan.show', $contract), false)
        ->assertSee('name="apply_to"', false);
});

it('shows the payoff for the rebate asked, then settles at it', function () {
    [$owner, , $contract] = onTheWeb();

    $this->actingAs($owner)->get(route('app.contracts.settle.show', ['contract' => $contract, 'rebate_type' => 'percent', 'rebate_value' => '50']))->assertOk()
        ->assertSee('787.50')->assertSee('37.50');

    $this->actingAs($owner)->post(route('app.contracts.settle.store', $contract), ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => '787.50', 'method' => 'cash'])
        ->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('status');
    expect(Contract::withoutGlobalScopes()->find($contract->id)->status)->toBe('settled');
});

it('sends a stale payoff back with the new figure', function () {
    [$owner, , $contract] = onTheWeb();
    app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), '100.00', 'cash');

    $this->actingAs($owner)->post(route('app.contracts.settle.store', $contract), ['rebate_type' => 'percent', 'rebate_value' => '50', 'payoff' => '787.50', 'method' => 'cash'])
        ->assertRedirect()->assertSessionHasErrors('payoff');
    expect(Contract::withoutGlobalScopes()->find($contract->id)->status)->toBe('active');
});

it('previews a new plan side by side, then confirms it with the reason', function () {
    [$owner, $tenant, $contract] = onTheWeb();
    $change = ['type' => 'reschedule', 'count' => '7', 'first_due_date' => '2026-12-01'];

    $this->actingAs($owner)->get(route('app.contracts.plan.show', ['contract' => $contract] + $change))->assertOk()
        ->assertSee('117.90')->assertSee('275.00');

    $this->actingAs($owner)->post(route('app.contracts.plan.store', $contract), $change + ['reason' => 'Lost his job'])
        ->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('status');
    expect(asTenant($tenant, fn () => ContractAmendment::count()))->toBe(1);
    $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertSee('Lost his job');
});

it('asks for the reason before the plan changes, and says so when it is missing', function () {
    [$owner, $tenant, $contract] = onTheWeb();
    $change = ['type' => 'skip', 'installment_id' => installmentsOfContract($contract)->firstWhere('number', 3)->id];
    $preview = route('app.contracts.plan.show', ['contract' => $contract] + $change);

    $this->actingAs($owner)->get($preview)->assertOk()
        ->assertSee('name="reason" rows="2" maxlength="500" required', false)->assertDontSee('Reason (optional)');
    $this->actingAs($owner)->from($preview)->followingRedirects()->post(route('app.contracts.plan.store', $contract), $change + ['reason' => ' '])
        ->assertOk()->assertSee('Say why the plan is changing.');
    expect(asTenant($tenant, fn () => ContractAmendment::count()))->toBe(0);
});

it('lets the payment form choose the instalment', function () {
    [$owner, , $contract] = onTheWeb();
    $last = installmentsOfContract($contract)->last();

    $this->actingAs($owner)->post(route('app.contracts.payments.store', $contract), ['amount' => '275.00', 'method' => 'cash', 'apply_to' => $last->id, 'idempotency_key' => 'web-apply-1'])
        ->assertRedirect();

    expect(installmentsOfContract($contract)->last()->status)->toBe('paid')
        ->and(installmentsOfContract($contract)->firstWhere('number', 2)->status)->toBe('pending');
});

it('takes one payment across a customer’s contracts from their page', function () {
    [$owner, $tenant, $contract] = onTheWeb();
    $customer = Contract::withoutGlobalScopes()->find($contract->id)->customer_id;
    $second = openContract($tenant, ['customer_id' => $customer, 'start_date' => '2026-09-15', 'first_due_date' => '2026-10-15']);

    $this->actingAs($owner)->get(route('app.customers.payment.show', $customer))->assertOk()->assertSee($second->reference());
    $this->actingAs($owner)->post(route('app.customers.payment.store', $customer), ['amount' => '400.00', 'method' => 'cash', 'idempotency_key' => 'web-batch-1'])
        ->assertRedirect(route('app.customers.show', $customer))->assertSessionHas('status');

    expect(asTenant($tenant, fn () => Transaction::whereNotNull('batch_id')->count()))->toBe(2);
});

it('starts the payment across contracts at each one’s next instalment already due, and leaves open accounts out', function () {
    [$owner, $tenant, $contract] = onTheWeb();
    $customer = Contract::withoutGlobalScopes()->find($contract->id)->customer_id;
    // By 7 October: 1 Aug, 1 Sep and 1 Oct are all due on the first, 15 Sep on the second; the one on the web next owes on 1 Dec.
    openContract($tenant, ['customer_id' => $customer, 'start_date' => '2026-07-01', 'first_due_date' => '2026-08-01']);
    openContract($tenant, ['customer_id' => $customer, 'start_date' => '2026-08-15', 'first_due_date' => '2026-09-15']);
    switchOn(Feature::OpenContracts);
    $tab = app(CreateContract::class)->handle($tenant, ['customer_id' => $customer, 'type' => 'open', 'opening_balance' => '250.00', 'start_date' => '2026-10-01'], $owner);

    // 100.00 + 100.00, as each contract's own form would start. Still owed on what this payment can reach:
    // 825.00 + 300.00 + 300.00; the open account's 250.00 is not part of it.
    $this->actingAs($owner)->get(route('app.customers.payment.show', $customer))->assertOk()
        ->assertSee('value="200.00"', false)->assertSee('1,425.00')->assertDontSee($tab->reference());
});

it('starts at the soonest next instalment when nothing is due yet', function () {
    [$owner, $tenant, $contract] = onTheWeb();
    $customer = Contract::withoutGlobalScopes()->find($contract->id)->customer_id;
    // Next due: 275.00 on 1 Dec on the first, 100.00 on 15 Nov on this one.
    openContract($tenant, ['customer_id' => $customer, 'start_date' => '2026-10-15', 'first_due_date' => '2026-11-15']);

    $this->actingAs($owner)->get(route('app.customers.payment.show', $customer))->assertOk()->assertSee('value="100.00"', false);
});

it('keeps a collector to payments: no settling, no plan changes', function () {
    [, $tenant, $contract] = onTheWeb();
    $collector = memberAs('collector', $tenant);

    $this->actingAs($collector)->get(route('app.contracts.show', $contract))->assertOk()
        ->assertDontSee(route('app.contracts.settle.show', $contract), false)
        ->assertDontSee(route('app.contracts.plan.show', $contract), false);
    $this->actingAs($collector)->post(route('app.contracts.settle.store', $contract), ['payoff' => '825.00', 'method' => 'cash'])->assertForbidden();
});
