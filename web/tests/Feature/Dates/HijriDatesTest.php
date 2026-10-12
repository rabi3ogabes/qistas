<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Settings\TenantSettings;
use App\Support\Hijri;

/*
| Hijri dates (Sprint 2 Task 2, Win Plan CT26). A workspace shows them beside the Gregorian date or first, on its pages
| and its PDFs, and may type a date in Hijri anywhere it picks one; what is stored is always the Gregorian day. The
| wording is the same as the app's: both are checked against shared/hijri-vectors.json.
|
| openContract(): three monthly instalments of 100.00 from 1 February 2026, which is 13 Shaban 1447.
*/

beforeEach(fn () => $this->travelTo('2026-01-15 09:00:00'));

function hijriMode(Tenant $tenant, string $mode): void
{
    switchOn(Feature::HijriDates);
    TenantSettings::for($tenant)->set('dates.hijri', $mode);
}

dataset('worded samples', fn () => collect(json_decode((string) file_get_contents(dirname(__DIR__, 4).'/shared/hijri-vectors.json'), true)['samples'])
    ->mapWithKeys(fn ($s) => [$s['gregorian'] => [$s]])->all());

it('words each sample as the app does, in every language', function (array $sample) {
    foreach ($sample['words'] as $language => $words) {
        app()->setLocale($language);
        expect(Hijri::format($sample['gregorian']))->toBe($words, $language);
    }
})->with('worded samples');

describe('the setting', function () {
    it('is offered on the tools page once the switch is on, and is off until the owner chooses', function () {
        [, $tenant] = apiOwner();
        switchOn(Feature::HijriDates);

        $tool = collect($this->getJson('/api/v1/settings/tools')->assertOk()->json('data'))->firstWhere('key', 'dates.hijri');

        expect($tool)->toMatchArray(['type' => 'select', 'value' => 'off'])
            ->and(array_column($tool['options'], 'value'))->toBe(['off', 'beside', 'first']);
    });

    it('tells the app how to show dates', function () {
        [, $tenant] = apiOwner();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.hijri_dates', 'off');

        hijriMode($tenant, 'first');

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.hijri_dates', 'first');
    });
});

describe('on the pages', function () {
    it('shows the Gregorian date alone while it is off', function () {
        [$owner, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($owner)->get("/app/contracts/{$contract->id}")->assertOk()->assertSee('1 Feb 2026')->assertDontSee('Shaban');
    });

    it('shows the Hijri date beside the Gregorian one', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        $contract = openContract($tenant);

        $this->actingAs($owner)->get("/app/contracts/{$contract->id}")->assertOk()
            // The schedule's cell, the other calendar beneath; and in a sentence, in brackets.
            ->assertSee('1 Feb 2026<span class="day-other">13 Shaban 1447 AH</span>', false)
            ->assertSee('due 1 Feb 2026 (13 Shaban 1447 AH).');
    });

    it('shows the Hijri date first when the owner asks', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'first');
        $contract = openContract($tenant);

        $this->actingAs($owner)->get("/app/contracts/{$contract->id}")->assertOk()
            ->assertSee('13 Shaban 1447 AH<span class="day-other">1 Feb 2026</span>', false)
            ->assertSee('due 13 Shaban 1447 AH (1 Feb 2026).');
    });

    it('goes back to the Gregorian date alone when the platform switches it off', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        app(FeatureControl::class)->setState(Feature::HijriDates, PlatformState::Off, null, null);
        $contract = openContract($tenant);

        $this->actingAs($owner)->get("/app/contracts/{$contract->id}")->assertOk()->assertDontSee('Shaban');
    });

    it('shows it on the payments list and the contracts list', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        $contract = openContract($tenant);
        $this->actingAs($owner)->get('/app/contracts')->assertOk()->assertSee('13 Shaban 1447 AH');

        app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), '100.00', 'cash');

        $this->actingAs($owner)->get('/app/payments')->assertOk()->assertSee('26 Rajab 1447 AH');
    });

    it('puts it on the contract statement PDF', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        $contract = openContract($tenant);
        // Issued on another day than the contract's, so each date on the page is its own.
        $this->travelTo('2026-01-20 09:00:00');

        $pdf = $this->actingAs($owner)->get("/app/contracts/{$contract->id}/statement")->assertOk()->getContent();

        expect(normalised(pdfText($pdf)))->toContain(normalised('Contract date 15 Jan 2026 (26 Rajab 1447 AH)'))
            ->toContain(normalised('20 Jan 2026 (1 Shaban 1447 AH)'));
    });
});

describe('typing a Hijri date', function () {
    it('offers a Hijri field beside the date fields only while it is on', function () {
        [$owner, $tenant] = owner();
        customerIn($tenant);
        $this->actingAs($owner)->get('/app/contracts/create')->assertOk()->assertDontSee('first_due_date_hijri');

        hijriMode($tenant, 'beside');

        $this->actingAs($owner)->get('/app/contracts/create')->assertOk()->assertSee('name="first_due_date_hijri"', false)->assertSee('name="start_date_hijri"', false);
    });

    it('stores a payment dated in Hijri as its Gregorian day', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        $contract = openContract($tenant);
        $this->travelTo('2026-02-05 12:00:00');

        $this->actingAs($owner)->post("/app/contracts/{$contract->id}/payments", ['amount' => '100.00', 'method' => 'cash', 'paid_at_hijri' => '١٣/٠٨/١٤٤٧', 'idempotency_key' => 'web-hijri-1'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $paid = asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'payment')->sole());
        expect($paid->paid_at->format('Y-m-d'))->toBe('2026-02-01');
    });

    it('stores a payment across a customer’s contracts dated in Hijri as its Gregorian day', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        switchOn(Feature::PaymentTargeting);
        $contract = openContract($tenant);
        $this->travelTo('2026-02-05 12:00:00');

        $this->actingAs($owner)->post("/app/customers/{$contract->customer_id}/payment", ['amount' => '100.00', 'method' => 'cash', 'paid_at_hijri' => '1447-08-13'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $paid = asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'payment')->sole());
        expect($paid->paid_at->format('Y-m-d'))->toBe('2026-02-01');
    });

    it('names the Hijri field when the date does not exist', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        $contract = openContract($tenant);

        $this->actingAs($owner)->post("/app/contracts/{$contract->id}/payments", ['amount' => '100.00', 'method' => 'cash', 'paid_at_hijri' => '31/13/1447', 'idempotency_key' => 'web-hijri-2'])
            ->assertSessionHasErrors('paid_at_hijri');
    });

    it('makes a contract whose first instalment is typed in Hijri', function () {
        [$owner, $tenant] = owner();
        hijriMode($tenant, 'beside');
        $customer = customerIn($tenant);

        $this->actingAs($owner)->post('/app/contracts', [
            'customer_id' => $customer->id, 'type' => 'scheduled', 'principal' => '300.00', 'down_payment' => '0', 'markup_type' => 'none',
            'installment_count' => '3', 'frequency' => 'monthly', 'start_date' => '2026-01-15', 'first_due_date' => '2026-03-01', 'first_due_date_hijri' => '1447-08-13',
        ])->assertRedirect()->assertSessionHasNoErrors();

        expect(asTenant($tenant, fn () => Contract::where('customer_id', $customer->id)->sole())->first_due_date->format('Y-m-d'))->toBe('2026-02-01');
    });
});
