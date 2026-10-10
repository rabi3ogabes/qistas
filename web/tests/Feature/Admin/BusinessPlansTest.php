<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| The Businesses pages of the admin area: every real business with its owner and plan, and a super admin putting one
| on another plan (Pro for a month after a bank transfer, Pro with no end, back to Free), always with a reason, written
| to the audit log. A plan change never deletes anything. Other staff may look; nobody else knows the pages exist.
| Only names, owners, plans and counts are shown, never a business's customers or money.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
});

function planStaff(string $role = 'super_admin', string $name = 'Layla Haddad'): User
{
    return User::factory()->create(['name' => $name, 'platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** @return array{0: User, 1: Tenant} an owner and their business, on Free */
function business(string $name = 'Al-Fares Electronics', string $email = 'omar@alfares.test'): array
{
    [$owner, $tenant] = makeAccount(['name' => 'Omar Fares', 'email' => $email], ['name' => $name]);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();
    $tenant->subscribeTo(Plan::where('key', 'free')->sole());

    return [$owner, $tenant->fresh()];
}

function changePlan(Tenant $tenant, array $form, ?User $who = null): TestResponse
{
    return test()->actingAs($who ?? planStaff())->put(route('admin.businesses.plan', $tenant), $form + ['reason' => 'Paid by bank transfer, receipt 4471']);
}

describe('the list of businesses', function () {
    it('shows every real business with its owner and plan, and never demo or test workspaces', function () {
        business();
        $demo = Tenant::factory()->create(['name' => 'Demo Shop', 'is_demo' => true]);
        $test = Tenant::factory()->create(['name' => 'Test Shop', 'is_test' => true]);

        $this->actingAs(planStaff())->get(route('admin.businesses.index'))->assertOk()
            ->assertSee('Al-Fares Electronics')->assertSee('omar@alfares.test')->assertSee('Free')
            ->assertDontSee('Demo Shop')->assertDontSee('Test Shop');
    });

    it('finds a business by its name or its owner’s email', function () {
        business();
        business('Salem Mobiles', 'salem@mobiles.test');

        $this->actingAs(planStaff())->get(route('admin.businesses.index', ['q' => 'salem@']))->assertOk()
            ->assertSee('Salem Mobiles')->assertDontSee('Al-Fares Electronics');
        $this->actingAs(planStaff())->get(route('admin.businesses.index', ['q' => 'fares']))->assertOk()
            ->assertSee('Al-Fares Electronics')->assertDontSee('Salem Mobiles');
    });

    it('shows only the businesses on one plan when asked', function () {
        [, $pro] = business('Salem Mobiles', 'salem@mobiles.test');
        $pro->subscribeTo(Plan::where('key', 'pro')->sole());
        business();

        $this->actingAs(planStaff())->get(route('admin.businesses.index', ['plan' => 'pro']))->assertOk()
            ->assertSee('Salem Mobiles')->assertDontSee('Al-Fares Electronics');
    });

    it('does not exist for anyone who is not platform staff', function () {
        [$owner, $tenant] = business();

        $this->actingAs($owner)->get(route('admin.businesses.index'))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.businesses.show', $tenant))->assertNotFound();
        $this->actingAs($owner)->put(route('admin.businesses.plan', $tenant), ['plan' => 'pro', 'period' => 'none', 'reason' => 'Mine'])->assertNotFound();
    });
});

describe('a business', function () {
    it('shows its owner, plan and how much of it is used, and never its customers', function () {
        [, $tenant] = business();
        customerIn($tenant, ['name' => 'Hidden Customer Name']);

        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSee('Al-Fares Electronics')->assertSee('Omar Fares')->assertSee('omar@alfares.test')
            ->assertSee('1 of '.Feature::FREE_CUSTOMERS)
            ->assertDontSee('Hidden Customer Name');
    });

    it('is not a demo or test workspace', function () {
        $demo = Tenant::factory()->create(['is_demo' => true]);

        $this->actingAs(planStaff())->get(route('admin.businesses.show', $demo))->assertNotFound();
        changePlan($demo, ['plan' => 'pro', 'period' => 'none'])->assertNotFound();
    });
});

describe('changing the plan', function () {
    it('puts a business on Pro for a month, and Pro’s features are theirs at once', function () {
        [, $tenant] = business();
        $admin = planStaff();

        changePlan($tenant, ['plan' => 'pro', 'period' => '1'], $admin)->assertRedirect(route('admin.businesses.show', $tenant))->assertSessionHas('status');

        $subscription = $tenant->fresh()->subscription;
        expect($subscription->plan->key)->toBe('pro')
            ->and($subscription->current_period_end->toDateString())->toBe('2026-11-11')
            ->and($tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and(Entitlements::for($tenant->fresh())->check(Feature::CustomBranding)->enabled())->toBeTrue();

        $audit = AuditLog::where('action', 'admin.plan_changed')->sole();
        expect($audit->tenant_id)->toBe($tenant->id)->and($audit->user_id)->toBe($admin->id)
            ->and($audit->changes)->toMatchArray([
                'before' => ['plan' => 'free', 'until' => null],
                'after' => ['plan' => 'pro', 'until' => '2026-11-11'],
                'reason' => 'Paid by bank transfer, receipt 4471',
            ]);
    });

    it('gives Pro for twelve months, or with no end, or until a chosen day', function (string $period, array $extra, ?string $until) {
        [, $tenant] = business();

        changePlan($tenant, ['plan' => 'pro', 'period' => $period] + $extra)->assertRedirect();

        expect($tenant->fresh()->subscription->current_period_end?->toDateString())->toBe($until);
    })->with([
        'twelve months' => ['12', [], '2027-10-11'],
        'no end' => ['none', [], null],
        'a chosen day' => ['date', ['until' => '2026-12-31'], '2026-12-31'],
    ]);

    it('puts a business back on Free, keeping everything it has', function () {
        [, $tenant] = business();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        foreach (range(1, Feature::FREE_CUSTOMERS + 3) as $i) {
            customerIn($tenant);
        }

        // Free never runs out, whatever period was picked.
        changePlan($tenant, ['plan' => 'free', 'period' => '3'])->assertRedirect();

        expect($tenant->fresh()->currentPlan()->key)->toBe('free')
            ->and($tenant->fresh()->subscription->current_period_end)->toBeNull()
            ->and(asTenant($tenant, fn () => Customer::count()))->toBe(Feature::FREE_CUSTOMERS + 3);
    });

    it('goes back to Free by itself when the time given is over', function () {
        [, $tenant] = business();
        changePlan($tenant, ['plan' => 'pro', 'period' => '1'])->assertRedirect();

        // Given to the end of 11 Nov, then kept for the grace days while a payment arrives (to the end of the last one).
        $grace = (int) config('qistas.billing.grace_days');
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(22, 0)->addDays($grace));
        expect($tenant->fresh()->currentPlan()->key)->toBe('pro');

        $this->travelTo(now()->addDay());
        expect($tenant->fresh()->currentPlan()->key)->toBe('free');
        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))->assertSee('Ended on 11 Nov 2026');
    });

    it('asks why, and refuses a day that has passed or a plan that does not exist', function () {
        [, $tenant] = business();

        $this->actingAs(planStaff())->put(route('admin.businesses.plan', $tenant), ['plan' => 'pro', 'period' => '1'])->assertSessionHasErrors('reason');
        changePlan($tenant, ['plan' => 'pro', 'period' => 'date', 'until' => '2026-10-10'])->assertSessionHasErrors('until');
        changePlan($tenant, ['plan' => 'platinum', 'period' => 'none'])->assertSessionHasErrors('plan');
        changePlan($tenant, ['plan' => 'pro', 'period' => '7'])->assertSessionHasErrors('period');

        expect($tenant->fresh()->currentPlan()->key)->toBe('free')->and(AuditLog::where('action', 'admin.plan_changed')->count())->toBe(0);
    });

    it('lists every change with who made it and why', function () {
        [, $tenant] = business();
        changePlan($tenant, ['plan' => 'pro', 'period' => '1'], planStaff(name: 'Layla Haddad'))->assertRedirect();

        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSee('Layla Haddad')->assertSee('Paid by bank transfer, receipt 4471')->assertSee('11 Nov 2026');
    });

    it('is only for a super admin; other staff may look', function () {
        [, $tenant] = business();
        $admin = planStaff('admin');

        $this->actingAs($admin)->get(route('admin.businesses.show', $tenant))->assertOk()->assertDontSee('name="reason"', false);
        changePlan($tenant, ['plan' => 'pro', 'period' => 'none'], $admin)->assertForbidden();
        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))->assertSee('name="reason"', false);
    });
});

it('is in the admin menu', function () {
    $this->actingAs(planStaff())->get(route('admin.home'))->assertSee(route('admin.businesses.index'), false);
});
