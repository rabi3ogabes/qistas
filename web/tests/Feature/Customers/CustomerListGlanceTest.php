<?php

use App\Actions\RecordPayment;
use App\Support\Format;
use Illuminate\Support\Carbon;

// The customer list at a glance (the report's "small gaps"): the book's totals over the list, and on every row the
// two things done most from it, reaching the customer and taking their money.

beforeEach(function () {
    // The default contract: 300.00 over 3 monthly instalments, due 1 Feb, 1 Mar and 1 Apr 2026.
    $this->travelTo('2026-03-10 12:00:00');
});

describe('the totals', function () {
    it('shows how many customers, this month’s instalments, what is still owed and what is late', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        customerIn($tenant, ['name' => 'No contract yet']);
        asTenant($tenant, fn () => app(RecordPayment::class)->handle($contract, '100.00', 'cash', paidAt: Carbon::parse('2026-02-03 12:00:00')));

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSeeInOrder(['data-total="customers"', '2'], false)
            ->assertSeeInOrder(['data-total="due-this-month"', Format::money('100.00', 'SAR')], false)
            ->assertSeeInOrder(['data-total="outstanding"', Format::money('200.00', 'SAR')], false)
            ->assertSeeInOrder(['data-total="overdue"', Format::money('100.00', 'SAR')], false)
            ->assertSee('data-total="overdue" data-tone="danger"', false);
    });

    it('stays the whole book while a search narrows the list', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        openContract($tenant);
        customerIn($tenant, ['name' => 'Zainab Fahad']);

        $this->actingAs($user)->get('/app/customers?q=Zainab')->assertOk()
            ->assertSeeInOrder(['data-total="customers"', '2'], false)
            ->assertSeeInOrder(['data-total="outstanding"', Format::money('300.00', 'SAR')], false);
    });

    it('is not shown before there is a first customer, and is calm when nothing is late', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->get('/app/customers')->assertOk()->assertDontSee('book-totals', false);

        $this->travelTo('2026-01-20 12:00:00');
        openContract($tenant);
        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSee('data-total="overdue"', false)->assertDontSee('data-total="overdue" data-tone="danger"', false);
    });
});

describe('the quick actions', function () {
    it('lets you call, message on WhatsApp and take a payment from the row', function () {
        [$user, $tenant] = owner();
        $customer = customerIn($tenant, ['name' => 'Layla Haddad', 'phone' => '+966 50 123 4567']);
        openContract($tenant, ['customer_id' => $customer->id]);

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSee('aria-label="Call Layla Haddad"', false)->assertSee('href="tel:+966501234567"', false)
            ->assertSee('aria-label="WhatsApp Layla Haddad"', false)->assertSee('href="https://wa.me/966501234567"', false)
            ->assertSee(e(route('app.customers.show', [$customer, 'pay' => 'next'])).'#pay', false);
    });

    it('offers no payment for a customer with nothing running, nor to someone who only views', function () {
        [$user, $tenant] = owner();
        $idle = customerIn($tenant, ['name' => 'Nothing running']);
        $busy = openContract($tenant);

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertDontSee(e(route('app.customers.show', [$idle, 'pay' => 'next'])), false)
            ->assertSee(e(route('app.customers.show', [$busy->customer_id, 'pay' => 'next'])), false);

        $this->actingAs(memberAs('viewer', $tenant))->get('/app/customers')->assertOk()
            ->assertDontSee('pay=next', false)->assertSee('aria-label="Call Nothing running"', false);
    });

    it('leaves out WhatsApp for a number without its country code', function () {
        [$user, $tenant] = owner();
        customerIn($tenant, ['name' => 'Local Number', 'phone' => '0501234567']);

        $this->actingAs($user)->get('/app/customers')->assertOk()
            ->assertSee('aria-label="Call Local Number"', false)->assertDontSee('aria-label="WhatsApp Local Number"', false);
    });

    it('opens the customer’s page with the payment ready', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->get(route('app.customers.show', [$contract->customer_id, 'pay' => 'next']))->assertOk()
            ->assertSee('<details class="pay-panel" id="pay" open', false)->assertSee('value="100.00"', false);
    });
});
