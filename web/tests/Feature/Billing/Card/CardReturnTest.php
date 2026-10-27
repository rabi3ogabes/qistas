<?php

use App\Billing\Card\CardSettings;
use App\Billing\Card\Data\SubscriptionCheckout;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| Back from paying (spec 2026-10-27 §4.2): Qistas asks Stripe about the checkout at once, so the owner lands on Pro
| without waiting for the webhook; a checkout left unpaid goes back to the checkout; another workspace's is not there.
*/

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    switchOn(Feature::CardBilling);
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_test_51Return', 'pk_test_51Return', User::factory()->create(['platform_role' => 'super_admin']));
    $settings->saveWebhook('we_1', 'whsec_return');
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
    [$this->owner, $this->tenant] = owner(['name' => 'Al-Fares Electronics']);
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id, 'stripe_customer_id' => 'cus_test_1', 'stripe_livemode' => false])->save();
});

/** A checkout Stripe opened for the workspace, for Pro by $period. */
function returnCheckout(FakeStripeGateway $stripe, Tenant $tenant, string $period = 'year', string $customer = 'cus_test_1'): string
{
    return $stripe->createSubscriptionCheckout(new SubscriptionCheckout(
        $customer, 'price_test_pro_'.$period, 14, ['tenant_id' => $tenant->id, 'plan' => 'pro', 'period' => $period], false, false, false, 'en', 'https://qistas.test/back',
    ), 'return-'.$period.'-'.$customer)->id;
}

it('lands the owner on Pro straight away, with the trial\'s end and what Pro opens', function () {
    $session = returnCheckout($this->stripe, $this->tenant);
    $this->stripe->completeSession($session, FakeStripeGateway::subscriptionData([
        'status' => 'trialing', 'trialEnd' => CarbonImmutable::parse('2026-11-10 09:00:00'), 'periodEnd' => CarbonImmutable::parse('2026-11-10 09:00:00'),
        'metadata' => ['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'period' => 'year'],
    ]));

    $this->actingAs($this->owner)->get(route('app.billing.card.return', ['session_id' => $session]))
        ->assertOk()
        ->assertSee(__('Welcome to :plan', ['plan' => 'Pro']))
        ->assertSee(__('Your free trial runs until :date. Nothing is charged before then.', ['date' => '10 November 2026']))
        ->assertSee(Feature::AdvancedReports->label())
        ->assertSee('href="'.route('app.dashboard').'"', false);

    expect($this->tenant->fresh()->currentPlan()->key)->toBe('pro')
        ->and($this->tenant->fresh()->subscription()->first()->status)->toBe('trialing');
});

it('says when the next charge is for a subscription paid at once', function () {
    $session = returnCheckout($this->stripe, $this->tenant);
    $this->stripe->completeSession($session, FakeStripeGateway::subscriptionData([
        'status' => 'active', 'periodEnd' => CarbonImmutable::parse('2027-10-27 09:00:00'),
        'metadata' => ['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'period' => 'year'],
    ]));

    $this->actingAs($this->owner)->get(route('app.billing.card.return', ['session_id' => $session]))
        ->assertOk()
        ->assertSee(__('Paid. :plan renews on :date.', ['plan' => 'Pro', 'date' => '27 October 2027']));
});

it('sends an unpaid checkout back to the checkout, saying nothing was charged', function (string $state) {
    $session = returnCheckout($this->stripe, $this->tenant, 'month');
    if ($state === 'expired') {
        $this->stripe->expireSession($session);
    }

    $this->actingAs($this->owner)->get(route('app.billing.card.return', ['session_id' => $session]))
        ->assertRedirect(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'month']))
        ->assertSessionHas('status', __('Your payment wasn\'t completed; nothing was charged.'));

    expect($this->tenant->fresh()->currentPlan()->key)->toBe('free');
})->with(['open', 'expired']);

it('does not show another workspace\'s checkout', function () {
    $session = returnCheckout($this->stripe, $this->tenant, 'year', 'cus_someone_else');

    $this->actingAs($this->owner)->get(route('app.billing.card.return', ['session_id' => $session]))->assertNotFound();
});

it('sends a return with no checkout to the billing page', function () {
    $this->actingAs($this->owner)->get(route('app.billing.card.return'))->assertRedirect(route('app.billing'));
});

it('is for the owner only', function () {
    $manager = memberAs('manager', $this->tenant);
    $manager->forceFill(['current_tenant_id' => $this->tenant->id])->save();

    $this->actingAs($manager)->get(route('app.billing.card.return', ['session_id' => 'cs_x']))->assertForbidden();
});
