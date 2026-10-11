<?php

use App\Actions\ChargeLateFees;
use App\Actions\RecordPayment;
use App\Actions\WaiveLateFee;
use App\Domain\LateFees\LateFeeSummary;
use App\Entitlements\Feature;
use App\Exports\WorkspaceData;
use App\Models\Contract;
use App\Models\LateFee;
use App\Models\Tenant;
use App\Settings\TenantSettings;

/*
| Late fees (Win Plan K4, CT20) as people meet them: the policy on the tools page and in the app, the fee lines on a
| contract, the waiver (owners and managers only, with a reason), the nightly command, the totals with charity apart,
| and the export.
*/

beforeEach(function () {
    $this->travelTo('2026-01-15 09:00:00');
});

/** A contract with a 10.00 fee on its first instalment, charged on 2 Feb (today is 5 Feb). */
function contractWithFee(Tenant $tenant, array $policy = []): Contract
{
    switchOn(Feature::LateFees);
    foreach (array_merge(['type' => 'fixed', 'amount' => '10', 'frequency' => 'once'], $policy) as $key => $value) {
        TenantSettings::for($tenant)->set("late_fees.{$key}", $value);
    }
    $contract = openContract($tenant);
    test()->travelTo('2026-02-05 12:00:00');
    app(ChargeLateFees::class)->handle(Tenant::find($tenant->id));

    return $contract;
}

function onlyFee(Contract $contract): LateFee
{
    return asTenant($contract->tenant, fn () => LateFee::where('contract_id', $contract->id)->sole());
}

describe('the policy', function () {
    it('is offered on the tools page once the switch is on, with amounts typed as amounts', function () {
        [, $tenant] = apiOwner();
        switchOn(Feature::LateFees);

        $tools = collect($this->getJson('/api/v1/settings/tools')->assertOk()->json('data'))->keyBy('key');

        expect($tools->keys()->all())->toBe(['late_fees.type', 'late_fees.amount', 'late_fees.frequency', 'late_fees.cap_installment', 'late_fees.cap_contract', 'late_fees.charity'])
            ->and($tools['late_fees.amount'])->toMatchArray(['type' => 'amount', 'value' => '0.00'])
            ->and(array_column($tools['late_fees.frequency']['options'], 'value'))->toBe(['once', 'daily', 'weekly', 'monthly']);
    });

    it('takes an amount with up to two decimals, and nothing else', function () {
        [, $tenant] = apiOwner();
        switchOn(Feature::LateFees);

        $this->putJson('/api/v1/settings/tools/late_fees.amount', ['value' => '7.5'])->assertOk()->assertJsonPath('data.value', '7.50');
        foreach (['abc', '-1', '2.555', ''] as $bad) {
            $this->putJson('/api/v1/settings/tools/late_fees.amount', ['value' => $bad])->assertStatus(422);
        }
        // Typed on an Arabic keyboard.
        $this->putJson('/api/v1/settings/tools/late_fees.amount', ['value' => '١٢٫٢٥'])->assertOk()->assertJsonPath('data.value', '12.25');

        expect(TenantSettings::for($tenant)->get('late_fees.amount'))->toBe('12.25');
    });

    it('is shown on the web tools page with a decimal field', function () {
        [$owner] = owner();
        switchOn(Feature::LateFees);

        $this->actingAs($owner)->get('/app/settings/tools')->assertOk()
            ->assertSee('Late fee')->assertSee('inputmode="decimal"', false);
    });
});

describe('on a contract', function () {
    it('lists the fees, the policy it was made with and counts them in what is owed', function () {
        [, $tenant] = apiOwner();
        $contract = contractWithFee($tenant);

        $data = $this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()->json('data');

        expect($data['late_fee_policy'])->toMatchArray(['type' => 'fixed', 'amount' => '10.00', 'frequency' => 'once', 'charity' => false])
            ->and($data['late_fees'][0])->toMatchArray([
                'installment_number' => 1, 'period' => 1, 'charged_on' => '2026-02-02', 'amount' => '10.00', 'paid_amount' => '0.00',
                'remaining' => '10.00', 'status' => 'open', 'charity' => false, 'waived_reason' => null,
            ])
            ->and($data['owed'])->toBe('310.00');
    });

    it('includes the fees in what the customer owes', function () {
        [, $tenant] = apiOwner();
        $contract = contractWithFee($tenant);

        $this->getJson("/api/v1/customers/{$contract->customer_id}")->assertOk()->assertJsonPath('data.owed', '310.00');
    });

    it('counts open fees in the dashboard’s outstanding and late totals', function () {
        [, $tenant] = apiOwner();
        contractWithFee($tenant);

        // 300.00 still owed on instalments, 100.00 of it late, and the 10.00 fee, which is late by nature.
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.outstanding', '310.00')->assertJsonPath('data.overdue', '110.00');
    });

    it('sorts customers by what they owe, fees included', function () {
        [, $tenant] = apiOwner();
        $withFee = contractWithFee($tenant);
        $other = openContract($tenant, ['principal' => '305.00']);

        $order = collect($this->getJson('/api/v1/customers?sort=balance')->assertOk()->json('data'))->pluck('id')->all();

        expect($order)->toBe([$withFee->customer_id, $other->customer_id]);
    });

    it('previews a payment with what goes to fees', function () {
        [, $tenant] = apiOwner();
        $contract = contractWithFee($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments/preview", ['amount' => '105'])->assertOk()
            ->assertJsonPath('data.late_fees', '5.00')->assertJsonPath('data.owed_after', '205.00');
    });

    it('lets an owner waive a fee with a reason', function () {
        [, $tenant] = apiOwner();
        $contract = contractWithFee($tenant);
        $fee = onlyFee($contract);

        $this->postJson("/api/v1/late-fees/{$fee->id}/waive", [])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->postJson("/api/v1/late-fees/{$fee->id}/waive", ['reason' => 'Came in the next day'])->assertOk()
            ->assertJsonPath('data.status', 'waived')->assertJsonPath('data.waived_reason', 'Came in the next day');
    });

    it('lets only owners and managers waive', function () {
        [, $tenant] = owner();
        $contract = contractWithFee($tenant);
        $fee = onlyFee($contract);

        apiMember('collector', $tenant);
        $this->postJson("/api/v1/late-fees/{$fee->id}/waive", ['reason' => 'Please'])->assertForbidden();

        apiMember('manager', $tenant);
        $this->postJson("/api/v1/late-fees/{$fee->id}/waive", ['reason' => 'Fine'])->assertOk();
    });

    it('hides another workspace’s fee', function () {
        [, $other] = owner();
        $contract = contractWithFee($other);
        apiOwner();

        $this->postJson('/api/v1/late-fees/'.onlyFee($contract)->id.'/waive', ['reason' => 'x'])->assertNotFound();
    });

    it('shows the fees on the web contract page, with the waiver for an owner', function () {
        [$owner, $tenant] = owner();
        $contract = contractWithFee($tenant);
        $fee = onlyFee($contract);

        $this->actingAs($owner)->get("/app/contracts/{$contract->id}")->assertOk()
            ->assertSee('Late fees')->assertSee(route('app.late-fees.waive', $fee), false);

        $this->actingAs($owner)->post(route('app.late-fees.waive', $fee), ['reason' => 'Goodwill'])
            ->assertRedirect()->assertSessionHas('status');
        expect(onlyFee($contract)->status)->toBe('waived');
    });
});

describe('the nightly command', function () {
    it('charges every workspace once a day', function () {
        [, $tenant] = owner();
        switchOn(Feature::LateFees);
        foreach (['type' => 'fixed', 'amount' => '5', 'frequency' => 'daily'] as $key => $value) {
            TenantSettings::for($tenant)->set("late_fees.{$key}", $value);
        }
        $contract = openContract($tenant);

        $this->travelTo('2026-02-03 12:00:00');
        $this->artisan('qistas:charge-late-fees')->assertSuccessful();
        $this->artisan('qistas:charge-late-fees')->assertSuccessful();

        expect(asTenant($tenant, fn () => LateFee::where('contract_id', $contract->id)->count()))->toBe(2);
    });
});

describe('the totals', function () {
    it('keeps charity fees apart from the shop’s', function () {
        [$owner, $shop] = owner();
        $kept = contractWithFee($shop);
        TenantSettings::for($shop)->set('late_fees.charity', true);
        $this->travelTo('2026-01-15 09:00:00');
        $given = openContract($shop);
        $this->travelTo('2026-02-05 12:00:00');
        app(ChargeLateFees::class)->handle(Tenant::find($shop->id));
        app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($kept->id), '110.00', 'cash');
        app(WaiveLateFee::class)->handle(onlyFee($given), 'Goodwill', $owner);

        $summary = asTenant($shop, fn () => app(LateFeeSummary::class)->between(now()->startOfYear(), now()));

        expect($summary)->toBe([
            'shop' => ['charged' => '10.00', 'collected' => '10.00', 'waived' => '0.00', 'open' => '0.00'],
            'charity' => ['charged' => '10.00', 'collected' => '0.00', 'waived' => '10.00', 'open' => '0.00'],
        ]);
    });

    it('goes out in the export, charity marked', function () {
        [, $tenant] = owner();
        $contract = contractWithFee($tenant, ['charity' => true]);

        // The rows are read lazily, so inside the workspace.
        $rows = asTenant($tenant, fn () => iterator_to_array(collect(WorkspaceData::sheets())->firstWhere('key', 'late_fees')->rows, false));

        expect($rows[0])->toBe([$contract->reference(), 1, '2026-02-02', 10.0, 0.0, 'Open', 'Yes', null]);
    });
});
