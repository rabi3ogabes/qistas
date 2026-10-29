<?php

use App\Actions\CreateContract;
use App\Actions\RecordCharge;
use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Installment;
use App\Support\Format;
use Illuminate\Support\Carbon;

// The customer's page is where the day's work happens: the customer, one contract's account line by line with a
// running balance, what is unpaid and late with a way to pay it, and the totals, on one screen.

beforeEach(function () {
    // The default contract: 300.00 over 3 monthly instalments, due 1 Feb, 1 Mar and 1 Apr 2026.
    $this->travelTo('2026-03-10 12:00:00');
});

function paidOnDesk(Contract $contract, string $amount, string $day): void
{
    asTenant($contract->tenant, fn () => app(RecordPayment::class)->handle($contract, $amount, 'cash', paidAt: Carbon::parse($day.' 12:00:00')));
}

function instalmentNumbered(Contract $contract, int $number): Installment
{
    return installmentsOfContract($contract)->firstWhere('number', $number);
}

describe('the account', function () {
    it('lists the contract line by line with what was added, what was paid and the balance after each', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        paidOnDesk($contract, '100.00', '2026-02-03');

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSee('data-ledger', false)
            ->assertSee('Running balance')->assertSee('Amounts in SAR')
            ->assertSeeInOrder(['data-line="sale"', '300.00', 'data-line="payment"', '100.00', '200.00'], false);
    });

    it('shows the totals: monthly instalment, instalments paid and left, paid, left and late', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        paidOnDesk($contract, '100.00', '2026-02-03');

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSeeInOrder(['data-total="instalment"', Format::money('100.00', 'SAR')], false)
            ->assertSeeInOrder(['data-total="paid-count"', '1'], false)
            ->assertSeeInOrder(['data-total="left-count"', '2'], false)
            ->assertSeeInOrder(['data-total="paid"', Format::money('100.00', 'SAR')], false)
            ->assertSeeInOrder(['data-total="left"', Format::money('200.00', 'SAR')], false)
            ->assertSeeInOrder(['data-total="late"', Format::money('100.00', 'SAR')], false)
            ->assertSee('data-tone="danger"', false);
    });

    it('marks an instalment that is due and unpaid, with a way to pay it from the page', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        paidOnDesk($contract, '100.00', '2026-02-03');
        $march = instalmentNumbered($contract, 2);
        $april = instalmentNumbered($contract, 3);

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSee('data-unpaid="'.$march->id.'"', false)
            ->assertDontSee('data-unpaid="'.$april->id.'"', false)
            ->assertSee('Unpaid. Record a payment')
            ->assertSee(e(route('app.customers.show', $contract->customer_id).'?contract='.$contract->id.'&pay='.$march->id), false);
    });

    it('opens the payment form for the instalment that was tapped, with its amount filled in', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        paidOnDesk($contract, '40.00', '2026-02-03');
        $february = instalmentNumbered($contract, 1);

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id).'?contract='.$contract->id.'&pay='.$february->id)->assertOk()
            ->assertSee('<details class="pay-panel" id="pay" open', false)
            ->assertSee('value="60.00"', false)
            ->assertSee('name="return_to" value="customer"', false);
    });

    it('records the payment and comes back to the customer with the same contract open', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), [
            'amount' => '100.00', 'method' => 'cash', 'return_to' => 'customer',
        ])->assertRedirect(route('app.customers.show', $contract->customer_id).'?contract='.$contract->id);

        expect(instalmentNumbered($contract, 1)->status)->toBe('paid');
    });

    it('still goes back to the contract when the payment was taken there', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), ['amount' => '100.00', 'method' => 'cash'])
            ->assertRedirect(route('app.contracts.show', $contract));
    });

    it('does not offer to take payments to someone who only views', function () {
        [, $tenant] = owner();
        $contract = openContract($tenant);
        $viewer = memberAs('viewer', $tenant);

        $this->actingAs($viewer)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSee('data-ledger', false)
            ->assertDontSee('Unpaid. Record a payment')
            ->assertDontSee('id="pay"', false);
    });
});

describe('choosing the contract', function () {
    it('opens on the contract that needs attention: late before running before finished', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        $late = openContract($tenant, ['customer_id' => $customer->id]);
        $fresh = openContract($tenant, ['customer_id' => $customer->id, 'start_date' => '2026-03-05', 'first_due_date' => '2026-04-05']);

        $this->actingAs($user)->get(route('app.customers.show', $customer))->assertOk()
            ->assertSee('data-contract="'.$late->id.'" aria-current="true"', false)
            ->assertDontSee('data-contract="'.$fresh->id.'" aria-current="true"', false);
    });

    it('shows the contract that was chosen, and lists every one with a link to its full page', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);
        $first = openContract($tenant, ['customer_id' => $customer->id]);
        $second = openContract($tenant, ['customer_id' => $customer->id, 'principal' => '900.00']);

        $this->actingAs($user)->get(route('app.customers.show', $customer).'?contract='.$second->id)->assertOk()
            ->assertSee('data-contract="'.$second->id.'" aria-current="true"', false)
            ->assertSee(url('/app/contracts/'.$first->id), false)
            ->assertSee(url('/app/contracts/'.$second->id), false);
    });

    it('ignores a contract that is not this customer’s', function () {
        [$user, $tenant] = owner();
        $mine = openContract($tenant);
        $someoneElses = openContract($tenant);

        $this->actingAs($user)->get(route('app.customers.show', $mine->customer_id).'?contract='.$someoneElses->id)->assertOk()
            ->assertSee('data-contract="'.$mine->id.'" aria-current="true"', false)
            ->assertDontSee('data-contract="'.$someoneElses->id.'"', false)
            ->assertDontSee(route('app.contracts.payments.store', $someoneElses), false);
    });

    it('offers to archive a finished contract from the page', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        paidOnDesk($contract, '300.00', '2026-03-01');

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSee(route('app.contracts.archive', $contract), false);
    });

    it('keeps an open account as charges and payments with a balance, without instalment counts', function () {
        switchOn(Feature::OpenContracts);
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $account = app(CreateContract::class)->handle($tenant, ['customer_id' => customerIn($tenant)->id, 'type' => 'open', 'start_date' => '2026-03-01']);
        asTenant($tenant, fn () => app(RecordCharge::class)->handle($account, '75.00'));

        $this->actingAs($user)->get(route('app.customers.show', $account->customer_id))->assertOk()
            ->assertSeeInOrder(['data-line="charge"', '75.00', '75.00'], false)
            ->assertSeeInOrder(['data-total="left"', Format::money('75.00', 'SAR')], false)
            ->assertDontSee('data-total="paid-count"', false);
    });

    it('keeps archived contracts folded away until one is asked for', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        paidOnDesk($contract, '300.00', '2026-03-01');
        asTenant($tenant, fn () => $contract->refresh()->forceFill(['archived_at' => now()])->save());

        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id))->assertOk()
            ->assertSee('Every contract for this customer is archived.')->assertDontSee('data-ledger', false);
        $this->actingAs($user)->get(route('app.customers.show', $contract->customer_id).'?contract='.$contract->id)->assertOk()
            ->assertSee('data-ledger', false)->assertDontSee(route('app.contracts.archive', $contract), false);
    });

    it('still invites a first contract when there is none', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant);

        $this->actingAs($user)->get(route('app.customers.show', $customer))->assertOk()
            ->assertSee('No contracts yet')->assertDontSee('data-ledger', false);
    });
});
