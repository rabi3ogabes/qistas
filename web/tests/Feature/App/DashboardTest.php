<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
});

it('sends guests to sign in', function () {
    $this->get('/app')->assertRedirect(route('login'));
});

it('is where sign-in and sign-up lead', function () {
    expect(config('fortify.home'))->toBe('/app')->and(route('app.dashboard', absolute: false))->toBe('/app');
});

it('shows the workspace and its plan', function () {
    [$user, $tenant] = makeAccount(tenant: ['name' => 'Al-Fares Electronics']);

    $this->actingAs($user)->get('/app')->assertOk()->assertSee('Al-Fares Electronics')->assertSee('Free');
});

it('shows the Pro plan to a Pro workspace and no upgrade prompt', function () {
    [$user, $tenant] = makeAccount();
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    $this->actingAs($user)->get('/app')->assertOk()->assertSee('Pro')->assertDontSee(url('/app/billing'), false);
});

it('offers an upgrade to a Free workspace', function () {
    [$user] = makeAccount();

    $this->actingAs($user)->get('/app')->assertSee(url('/app/billing'), false);
});

describe('the figures', function () {
    beforeEach(function () {
        [$this->user, $this->tenant] = makeAccount(tenant: ['currency' => 'SAR']);
        $this->a = openContract($this->tenant, ['principal' => '300.00', 'first_due_date' => '2026-09-01', 'start_date' => '2026-08-15']);
        $this->b = openContract($this->tenant, ['principal' => '100.00', 'installment_count' => 2, 'first_due_date' => '2026-10-07', 'start_date' => '2026-09-20']);
        app(RecordPayment::class)->handle($this->a, '100.00', 'cash', paidAt: Carbon::parse('2026-09-15 09:00:00'));
        app(RecordPayment::class)->handle($this->a, '60.00', 'cash', paidAt: Carbon::parse('2026-10-03 09:00:00'));
    });

    it('shows what is owed, what is overdue and what came in this month, in the workspace currency', function () {
        $page = $this->actingAs($this->user)->get('/app');

        $page->assertOk()
            ->assertSee(Format::money('240.00', 'SAR'))   // outstanding
            ->assertSee(Format::money('40.00', 'SAR'))    // overdue
            ->assertSee(Format::money('60.00', 'SAR'));   // collected this month
    });

    it('counts the active customers', function () {
        $this->actingAs($this->user)->get('/app')->assertOk()->assertSeeInOrder([__('Active customers'), '2']);
    });

    it('lists what falls due today, with who owes it and a link to the contract', function () {
        $name = asTenant($this->tenant, fn () => $this->b->customer->name);

        $this->actingAs($this->user)->get('/app')
            ->assertSee($name)
            ->assertSee(Format::money('50.00', 'SAR'))
            ->assertSee(url('/app/contracts/'.$this->b->id), false);
    });

    it('says so when nothing is due today', function () {
        $this->travelTo('2026-10-20 12:00:00');

        $this->actingAs($this->user)->get('/app')->assertSee('Nothing is due today.');
    });

    it('never shows another workspace’s figures', function () {
        [, $other] = makeAccount(tenant: ['currency' => 'SAR']);
        openContract($other, ['principal' => '9000.00', 'first_due_date' => '2026-10-07']);

        $this->actingAs($this->user)->get('/app')->assertDontSee(Format::money('9,000.00', 'SAR'))->assertDontSee('9,000');
    });
});

describe('plan usage', function () {
    it('shows how much of each limit is used', function () {
        [$user, $tenant] = makeAccount();
        customerIn($tenant);
        openContract($tenant); // brings its own customer: 2 customers, 1 contract

        $this->actingAs($user)->get('/app')
            ->assertSee('2 of 5')
            ->assertSee('1 of 5')
            ->assertSee('role="progressbar"', false)
            ->assertSee('aria-valuenow="2"', false)
            ->assertSee('aria-valuemax="5"', false);
    });

    it('warns as a limit gets close and when it is full', function () {
        [$user, $tenant] = makeAccount();
        foreach (range(1, 4) as $_) {
            customerIn($tenant);
        }
        $this->actingAs($user)->get('/app')->assertSee('data-level="high"', false);

        customerIn($tenant);
        $this->actingAs($user)->get('/app')->assertSee('data-level="full"', false);
    });

    it('shows unlimited for a plan without a limit', function () {
        [$user, $tenant] = makeAccount();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        customerIn($tenant);

        $this->actingAs($user)->get('/app')->assertSeeInOrder([__('Customers'), '1', __('Unlimited')])->assertDontSee('role="progressbar"', false);
    });

    it('follows the admin’s change to a limit', function () {
        [$user, $tenant] = makeAccount();
        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 9);

        $this->actingAs($user)->get('/app')->assertSee('0 of 9');
    });
});

describe('getting started', function () {
    it('guides an empty workspace through its first three steps', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->get('/app')
            ->assertSee('Get started')
            ->assertSee(url('/app/customers/create'), false)
            ->assertSee('data-done="false"', false)
            ->assertDontSee('data-done="true"', false);
    });

    it('ticks steps off as they happen', function () {
        [$user, $tenant] = makeAccount();
        openContract($tenant); // adds a customer and a contract, but no payment yet

        $page = $this->actingAs($user)->get('/app');

        expect(substr_count($page->getContent(), 'data-done="true"'))->toBe(2)
            ->and(substr_count($page->getContent(), 'data-done="false"'))->toBe(1);
    });

    it('goes away once the business is up and running', function () {
        [$user, $tenant] = makeAccount();
        $contract = openContract($tenant);
        app(RecordPayment::class)->handle($contract, '10.00', 'cash');

        $this->actingAs($user)->get('/app')->assertDontSee('Get started');
    });
});

describe('the app around it', function () {
    it('links to every section and marks where you are', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->get('/app')
            ->assertSee(route('app.dashboard'), false)
            ->assertSee(route('security'), false)
            ->assertSee('aria-current="page"', false);
    });

    it('asks an unverified person to verify their email, without blocking them', function () {
        $user = User::factory()->unverified()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)->get('/app')->assertOk()->assertSee('Verify your email')->assertSee(route('verification.send'), false);
    });

    it('does not nag someone who has verified', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->get('/app')->assertDontSee(route('verification.send'), false);
    });

    it('lets a viewer look', function () {
        [$user] = makeAccount();
        $viewer = User::factory()->create();
        $user->tenants()->first()->users()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer)->get('/app')->assertOk();
    });

    it('turns away someone with no workspace', function () {
        $this->actingAs(User::factory()->create())->get('/app')->assertForbidden();
    });

    it('signs out someone who has been suspended', function () {
        [$user] = makeAccount(['status' => 'suspended']);

        $this->actingAs($user)->get('/app')->assertRedirect(route('account.suspended'));
    });

    it('is right-to-left in Arabic', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->get('/app?lang=ar')->assertOk()->assertSee('lang="ar" dir="rtl"', false);
    });

    it('is not for search engines', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->get('/app')->assertSee('<meta name="robots" content="noindex">', false);
    });

    it('shows an upgrade sheet when something sent the person back with a limit message', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->withSession(['upgrade' => [
            'code' => 'limit_reached', 'message' => 'You have reached the limit of 5 customers on your plan.',
            'feature' => 'customers', 'limit' => 5, 'used' => 5, 'upgrade_url' => url('/app/billing'),
        ]])->get('/app')
            ->assertSee('<dialog', false)
            ->assertSee('data-auto-open', false)
            ->assertSee('You have reached the limit of 5 customers on your plan.')
            ->assertSee(url('/app/billing'), false);
    });

    it('shows no sheet normally', function () {
        [$user] = makeAccount();

        $this->actingAs($user)->get('/app')->assertDontSee('<dialog', false);
    });
});
