<?php

use App\Actions\RecordPayment;
use App\Domain\Investors\MainInvestor;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
 * Win Plan PP3 through the API the app uses: the investors list and each one's page, adding investors (Free keeps
 * the main investor only), deposits and withdrawals that are never edited but reversed, and who funds a new contract.
 * Collectors never see investors; accountants and above do.
 */

beforeEach(fn () => switchOn(Feature::Investors));

function onPro(Tenant $tenant): Tenant
{
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    return $tenant;
}

describe('the list', function () {
    it('starts with the business’s own capital and its figures', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant, ['principal' => '1000.00', 'markup_type' => 'fixed', 'markup_value' => '100.00', 'installment_count' => 10]);
        app(RecordPayment::class)->handle($contract, '110.00', 'cash');

        $this->getJson('/api/v1/investors')->assertOk()
            ->assertJsonPath('data.investors.0.name', 'Own capital')
            ->assertJsonPath('data.investors.0.is_main', true)
            ->assertJsonPath('data.investors.0.summary.wallet', '-890.00')
            ->assertJsonPath('data.investors.0.summary.out_in_contracts', '900.00')
            ->assertJsonPath('data.investors.0.summary.profit_earned', '10.00')
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.limit', 1);
    });

    it('is hidden from collectors and open to accountants and viewers', function (string $role, int $status) {
        [, $tenant] = owner();
        apiMember($role, $tenant);

        $this->getJson('/api/v1/investors')->assertStatus($status);
    })->with([
        'collector' => ['collector', 403],
        'accountant' => ['accountant', 200],
        'viewer' => ['viewer', 200],
    ]);

    it('stays readable when the feature is switched off, but nothing new can be added', function () {
        [, $tenant] = apiOwner();
        onPro($tenant);
        DB::table('platform_features')->where('feature_key', 'investors')->update(['state' => 'off']);

        $this->getJson('/api/v1/investors')->assertOk();
        $this->postJson('/api/v1/investors', ['name' => 'Khalid'])->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });
});

describe('adding investors', function () {
    it('keeps the Free plan to the main investor, with the way to upgrade', function () {
        apiOwner();

        $this->postJson('/api/v1/investors', ['name' => 'Khalid'])->assertStatus(402)->assertJsonPath('error.code', 'limit_reached');
    });

    it('adds a partner with an opening capital and a commission on Pro', function () {
        [, $tenant] = apiOwner();
        onPro($tenant);

        $id = $this->postJson('/api/v1/investors', [
            'name' => 'Khalid Al-Harbi', 'commercial_registration' => '1010123456', 'commission_percent' => '15', 'opening_capital' => '25000',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Khalid Al-Harbi')
            ->assertJsonPath('data.commission_percent', '15.00')
            ->assertJsonPath('data.summary.wallet', '25000.00')
            ->json('data.id');

        $this->getJson("/api/v1/investors/{$id}")->assertOk()
            ->assertJsonPath('data.entries.0.type', 'deposit')
            ->assertJsonPath('data.entries.0.amount', '25000.00');
    });

    it('lets only accountants and above add one', function () {
        [, $tenant] = owner();
        onPro($tenant);
        apiMember('viewer', $tenant);

        $this->postJson('/api/v1/investors', ['name' => 'Khalid'])->assertForbidden();
    });

    it('never shows another business’s investor', function () {
        apiOwner();
        $foreign = MainInvestor::for(Tenant::factory()->create());

        $this->getJson("/api/v1/investors/{$foreign->id}")->assertNotFound();
    });
});

describe('deposits and withdrawals', function () {
    it('records them and reverses a mistake instead of editing it', function () {
        [, $tenant] = apiOwner();
        $main = MainInvestor::for($tenant);

        $entry = $this->postJson("/api/v1/investors/{$main->id}/entries", ['type' => 'deposit', 'amount' => '5000', 'note' => 'Opening cash'])
            ->assertCreated()->assertJsonPath('data.amount', '5000.00')->json('data.id');
        $this->postJson("/api/v1/investors/{$main->id}/entries", ['type' => 'withdrawal', 'amount' => '1200.50'])
            ->assertCreated()->assertJsonPath('data.amount', '-1200.50');

        $this->postJson("/api/v1/investor-entries/{$entry}/reverse")->assertCreated()->assertJsonPath('data.amount', '-5000.00');
        $this->postJson("/api/v1/investor-entries/{$entry}/reverse")->assertStatus(422);

        $this->getJson("/api/v1/investors/{$main->id}")->assertJsonPath('data.summary.wallet', '-1200.50');
    });

    it('will not reverse what a payment credited: the payment is voided instead', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);
        app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        $credited = asTenant($tenant, fn () => InvestorEntry::query()->where('type', 'principal_back')->firstOrFail());

        $this->postJson("/api/v1/investor-entries/{$credited->id}/reverse")->assertStatus(422);
    });

    it('lets owners and managers reverse, not accountants', function () {
        [, $tenant] = owner();
        $main = MainInvestor::for($tenant);
        $entry = asTenant($tenant, fn () => InvestorEntry::query()->forceCreate(['investor_id' => $main->id, 'type' => 'deposit', 'amount' => '100', 'occurred_on' => '2026-10-01']));
        apiMember('accountant', $tenant);

        $this->postJson("/api/v1/investors/{$main->id}/entries", ['type' => 'deposit', 'amount' => '50'])->assertCreated();
        $this->postJson("/api/v1/investor-entries/{$entry->id}/reverse")->assertForbidden();
    });
});

describe('who funds a contract', function () {
    it('opens a contract funded by the chosen investor and says so', function () {
        [, $tenant] = apiOwner();
        onPro($tenant);
        $partner = asTenant($tenant, fn () => Investor::query()->forceCreate(['name' => 'Khalid', 'currency' => 'SAR']));

        $this->postJson('/api/v1/contracts', [
            'customer_id' => customerIn($tenant)->id, 'type' => 'scheduled', 'principal' => '600.00', 'installment_count' => 3,
            'frequency' => 'monthly', 'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07', 'investor_id' => $partner->id,
        ])->assertCreated()->assertJsonPath('data.investor.id', $partner->id)->assertJsonPath('data.investor.name', 'Khalid');
    });

    it('refuses an investor of another business, or an archived one', function () {
        [, $tenant] = apiOwner();
        $foreign = MainInvestor::for(Tenant::factory()->create());

        $this->postJson('/api/v1/contracts', [
            'customer_id' => customerIn($tenant)->id, 'type' => 'scheduled', 'principal' => '600.00', 'installment_count' => 3,
            'frequency' => 'monthly', 'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07', 'investor_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['investor_id']]]);
    });

    it('does not let a collector choose, so their contracts are funded by the main investor', function () {
        [, $tenant] = owner();
        apiMember('collector', $tenant);
        $main = MainInvestor::for($tenant);

        $this->postJson('/api/v1/contracts', [
            'customer_id' => customerIn($tenant)->id, 'type' => 'scheduled', 'principal' => '600.00', 'installment_count' => 3,
            'frequency' => 'monthly', 'start_date' => '2026-10-07', 'first_due_date' => '2026-11-07', 'investor_id' => $main->id,
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['investor_id']]]);
    });

    it('shows collectors who funded a contract no more than they see elsewhere', function () {
        [, $tenant] = owner();
        $contract = openContract($tenant);
        apiMember('collector', $tenant);

        expect($this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()->json('data'))->not->toHaveKey('investor');
    });
});

it('lists an investor’s contracts on its page', function () {
    [, $tenant] = apiOwner();
    $contract = openContract($tenant);
    $main = MainInvestor::for($tenant);

    $this->getJson("/api/v1/investors/{$main->id}")->assertOk()
        ->assertJsonPath('data.contracts.0.id', $contract->id)
        ->assertJsonPath('data.contracts.0.reference', Contract::withoutGlobalScopes()->find($contract->id)->reference())
        ->assertJsonCount(6, 'data.profit_by_month');
});
