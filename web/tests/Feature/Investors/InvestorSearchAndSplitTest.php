<?php

use App\Actions\Investors\CreateInvestor;
use App\Entitlements\Feature;
use App\Models\Investor;
use App\Models\Plan;
use App\Support\Format;

// The investors list can be searched, and an investor's page shows where their money is.

beforeEach(fn () => switchOn(Feature::Investors));

describe('searching investors', function () {
    it('finds a partner by part of the name, whatever the case', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Khalid Al-Harbi', 'commercial_registration' => '1010٢٣٤٥٦٧'], $user);
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Sara Partners'], $user);

        $this->actingAs($user)->get('/app/investors?q=khalid')->assertOk()
            ->assertSee('Khalid Al-Harbi')->assertDontSee('Sara Partners')->assertDontSee('Own capital')
            ->assertSee('value="khalid"', false);
    });

    it('finds by the commercial registration, typed in Arabic or Western digits', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Khalid Al-Harbi', 'commercial_registration' => '1010234567'], $user);
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Sara Partners'], $user);

        $this->actingAs($user)->get('/app/investors?q='.urlencode('٢٣٤٥'))->assertOk()
            ->assertSee('Khalid Al-Harbi')->assertDontSee('Sara Partners');
    });

    it('finds the business’s own capital by the name the reader sees', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Sara Partners'], $user);

        $this->actingAs($user)->get('/app/investors?q=own')->assertOk()->assertSee('Own capital')->assertDontSee('Sara Partners');
    });

    it('says when nothing matches, with a way back', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Sara Partners'], $user);

        $this->actingAs($user)->get('/app/investors?q=zzz')->assertOk()
            ->assertSee('No investors match “zzz”')->assertSee('Clear search')->assertDontSee('Working with partners?');
    });

    it('shows no search box while there is only the business’s own capital', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/investors')->assertOk()->assertDontSee('Search investors');
    });
});

describe('where the money is', function () {
    it('splits the money at work between what customers hold and what waits in the wallet', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Khalid Al-Harbi', 'opening_capital' => '1000'], $user);
        $partner = asTenant($tenant, fn () => Investor::query()->where('name', 'Khalid Al-Harbi')->sole());
        openContract($tenant, ['investor_id' => $partner->id]); // 300.00 goes out to the customer

        $this->actingAs($user)->get(route('app.investors.show', $partner))->assertOk()
            ->assertSee('Where the money is')->assertSee('money-ring', false)
            ->assertSeeInOrder(['data-part="out"', Format::money('300.00', 'SAR'), '30%'], false)
            ->assertSeeInOrder(['data-part="wallet"', Format::money('700.00', 'SAR'), '70%'], false)
            ->assertSee(Format::money('1000.00', 'SAR', 0));
    });

    it('explains how to start when no money is at work yet', function () {
        [$user, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        app(CreateInvestor::class)->handle($tenant, ['name' => 'Empty Partner'], $user);
        $partner = asTenant($tenant, fn () => Investor::query()->where('name', 'Empty Partner')->sole());

        $this->actingAs($user)->get(route('app.investors.show', $partner))->assertOk()
            ->assertSee('Nothing at work yet.')->assertDontSee('money-ring', false);
    });
});
