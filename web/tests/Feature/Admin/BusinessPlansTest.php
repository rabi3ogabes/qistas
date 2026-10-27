<?php

use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
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

describe('a business that pays by card', function () {
    beforeEach(function () {
        $this->stripe = new FakeStripeGateway;
        app()->instance(StripeGateway::class, $this->stripe);
    });

    it('shows the card subscription: where it comes from, its status and period, the next charge and the way to Stripe', function (bool $live, string $link) {
        [, $tenant] = cardPayingBusiness($this, ['livemode' => $live]);

        $this->actingAs(planStaff('admin'))->get(route('admin.businesses.show', $tenant))->assertOk()
            ->assertSee('Card (Stripe)')->assertSee('Active')->assertSee('Yearly')
            ->assertSee('Next charge on 11 Oct 2027')
            ->assertSee($link, false)
            ->assertDontSee('Cancel the card subscription');
    })->with([
        'test mode' => [false, 'https://dashboard.stripe.com/test/subscriptions/sub_test_9'],
        'live mode' => [true, 'https://dashboard.stripe.com/subscriptions/sub_test_9'],
    ]);

    it('shows the end of the free trial, or the end when it will not renew', function () {
        [, $tenant] = cardPayingBusiness($this, ['status' => 'trialing', 'until' => '2026-10-25 09:00:00']);
        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))->assertSee('Free trial until 25 Oct 2026');

        [, $other] = cardPayingBusiness($this, ['auto_renew' => false, 'email' => 'salem@mobiles.test', 'n' => '10']);
        $this->actingAs(planStaff())->get(route('admin.businesses.show', $other))->assertSee('Ends on 11 Oct 2027, will not renew');
    });

    it('cancels it now with a refund for the unused time, and the business is on Free with nothing deleted', function () {
        [, $tenant] = cardPayingBusiness($this);
        $admin = planStaff();

        $this->actingAs($admin)->post(route('admin.businesses.card.cancel', $tenant), ['when' => 'now'])
            ->assertRedirect(route('admin.businesses.show', $tenant))
            ->assertSessionHas('status', 'The card subscription is cancelled and the unused time refunded. Al-Fares Electronics is on Free.');

        expect($this->stripe->calls('cancelNow'))->toBe([['sub_test_9', true, true]])
            ->and($tenant->fresh()->currentPlan()->key)->toBe('free')
            ->and(AuditLog::query()->where('action', 'admin.card_subscription_cancelled')->where('tenant_id', $tenant->id)->where('user_id', $admin->id)->sole()->changes)->toMatchArray(['when' => 'now']);
    });

    it('cancels it at the end of the period, keeping Pro until then', function () {
        [, $tenant] = cardPayingBusiness($this);

        $this->actingAs(planStaff())->post(route('admin.businesses.card.cancel', $tenant), ['when' => 'period_end'])
            ->assertRedirect(route('admin.businesses.show', $tenant))
            ->assertSessionHas('status', 'The card subscription ends on 11 Oct 2027 and will not renew.');

        expect($this->stripe->calls('setCancelAtPeriodEnd'))->toBe([['sub_test_9', true]])
            ->and($tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and($tenant->fresh()->subscription->auto_renew)->toBeFalse();
        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))->assertSee('Ends on 11 Oct 2027, will not renew');
    });

    it('is a super admin’s decision, and says when or refuses', function () {
        [, $tenant] = cardPayingBusiness($this);

        $this->actingAs(planStaff('admin'))->post(route('admin.businesses.card.cancel', $tenant), ['when' => 'now'])->assertForbidden();
        $this->actingAs(planStaff())->post(route('admin.businesses.card.cancel', $tenant), ['when' => 'tomorrow'])->assertSessionHasErrors('when');
        expect($this->stripe->calls('cancelNow'))->toBe([]);
    });

    it('refuses when the business does not pay by card', function () {
        [, $tenant] = business();

        $this->actingAs(planStaff())->post(route('admin.businesses.card.cancel', $tenant), ['when' => 'now'])
            ->assertSessionHasErrors(['card' => 'There is no card subscription to change.']);
    });

    it('warns before a plan change by hand while the card subscription runs, and refuses it until it is cancelled', function () {
        [, $tenant] = cardPayingBusiness($this);

        $this->actingAs(planStaff())->get(route('admin.businesses.show', $tenant))
            ->assertSee('This business pays for Pro by card.')
            ->assertSee('Cancel the card subscription');
        changePlan($tenant, ['plan' => 'free', 'period' => 'none'])
            ->assertSessionHasErrors(['plan' => 'This business pays by card. Cancel the card subscription first, so Stripe stops charging them.']);

        expect($tenant->fresh()->currentPlan()->key)->toBe('pro');
    });
});

/**
 * An owner and their business on Pro by card, yearly until 11 Oct 2027, which Stripe holds too.
 *
 * @param  array{livemode?: bool, status?: string, until?: string, auto_renew?: bool, email?: string, n?: string}  $options
 * @return array{0: User, 1: Tenant}
 */
function cardPayingBusiness(object $test, array $options = []): array
{
    [$owner, $tenant] = business(email: $options['email'] ?? 'omar@alfares.test');
    $live = $options['livemode'] ?? false;
    $status = $options['status'] ?? 'active';
    $until = CarbonImmutable::parse($options['until'] ?? '2027-10-11 09:00:00');
    $renews = $options['auto_renew'] ?? true;
    $n = $options['n'] ?? '9';
    $tenant->forceFill(['stripe_customer_id' => 'cus_test_'.$n, 'stripe_livemode' => $live])->save();
    $test->stripe->seedSubscription(FakeStripeGateway::subscriptionData([
        'id' => 'sub_test_'.$n, 'customerId' => 'cus_test_'.$n, 'status' => $status, 'periodEnd' => $until,
        'trialEnd' => $status === 'trialing' ? $until : null, 'cancelAtPeriodEnd' => ! $renews,
        'metadata' => ['tenant_id' => $tenant->id, 'plan' => 'pro', 'period' => 'year'],
    ]));
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), $status, $until, [
        'source' => 'stripe', 'stripe_subscription_id' => 'sub_test_'.$n, 'period' => 'year', 'auto_renew' => $renews,
    ]);

    return [$owner, $tenant->fresh()];
}
