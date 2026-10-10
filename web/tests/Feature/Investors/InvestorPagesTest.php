<?php

use App\Domain\Investors\MainInvestor;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Plan;
use App\Models\Tenant;

/* Win Plan PP3 on the web app (the PC version): the investors page, one investor's page, and "Funded by" on contracts. */

beforeEach(fn () => switchOn(Feature::Investors));

function investorsOnPro(Tenant $tenant): Tenant
{
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    return $tenant;
}

it('lists the business’s own capital with its money, and puts Investors in the menu', function () {
    [$user, $tenant] = owner();
    openContract($tenant);

    $this->actingAs($user)->get('/app/investors')->assertOk()
        ->assertSee('Own capital')->assertSee('Out in contracts')->assertSee('300.00')
        ->assertSee(route('app.investors.index'), false);
});

it('keeps investors away from collectors, page and menu alike', function () {
    [, $tenant] = owner();
    $collector = memberAs('collector', $tenant);

    $this->actingAs($collector)->get('/app/investors')->assertForbidden();
    $this->actingAs($collector)->get('/app')->assertOk()->assertDontSee(route('app.investors.index'), false);
});

it('offers the upgrade instead of a second investor on the Free plan', function () {
    [$user] = owner();

    $this->actingAs($user)->get('/app/investors')->assertOk()
        ->assertSee(url('/app/billing'), false)->assertDontSee(route('app.investors.create'), false);
});

it('adds a partner and opens their page', function () {
    [$user, $tenant] = owner();
    investorsOnPro($tenant);

    $response = $this->actingAs($user)->post('/app/investors', ['name' => 'Khalid Al-Harbi', 'commission_percent' => '10', 'opening_capital' => '20000']);

    $partner = asTenant($tenant, fn () => Investor::query()->where('name', 'Khalid Al-Harbi')->sole());
    $response->assertRedirect(route('app.investors.show', $partner));
    $this->actingAs($user)->get(route('app.investors.show', $partner))->assertOk()
        ->assertSee('Khalid Al-Harbi')->assertSee('20,000.00')->assertSee('Opening capital');
});

it('records money put in, and reverses it with one confirmation', function () {
    [$user, $tenant] = owner();
    $main = MainInvestor::for($tenant);

    $this->actingAs($user)->from(route('app.investors.show', $main))
        ->post(route('app.investors.entries.store', $main), ['type' => 'deposit', 'amount' => '7500', 'note' => 'From savings'])
        ->assertRedirect(route('app.investors.show', $main));
    $entry = asTenant($tenant, fn () => InvestorEntry::query()->where('type', 'deposit')->sole());

    $this->actingAs($user)->from(route('app.investors.show', $main))
        ->post(route('app.investor-entries.reverse', $entry))->assertRedirect(route('app.investors.show', $main));

    expect(asTenant($tenant, fn () => InvestorEntry::query()->where('reverses_entry_id', $entry->id)->sole()->amount))->toBe('-7500.0000');
    $this->actingAs($user)->get(route('app.investors.show', $main))->assertSee('From savings')->assertSee('Reversed');
});

it('asks who funds a new contract once there are partners, and names them on the contract', function () {
    [$user, $tenant] = owner();
    investorsOnPro($tenant);
    customerIn($tenant);
    $partner = asTenant($tenant, fn () => Investor::query()->forceCreate(['name' => 'Khalid', 'currency' => 'SAR']));

    $this->actingAs($user)->get('/app/contracts/create')->assertOk()
        ->assertSee('Funded by')->assertSee('name="investor_id"', false)->assertSee('Khalid');

    $contract = openContract($tenant, ['investor_id' => $partner->id]);
    $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertSee('Funded by')->assertSee('Khalid');
});

it('does not ask who funds a contract while the business has only its own capital', function () {
    [$user, $tenant] = owner();
    customerIn($tenant);

    $this->actingAs($user)->get('/app/contracts/create')->assertOk()->assertDontSee('name="investor_id"', false);
});

it('never opens another business’s investor', function () {
    [$user] = owner();
    $foreign = MainInvestor::for(Tenant::factory()->create());

    $this->actingAs($user)->get(route('app.investors.show', $foreign))->assertNotFound();
    expect(Contract::withoutGlobalScopes()->count())->toBe(0);
});
