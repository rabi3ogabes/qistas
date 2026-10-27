<?php

use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Models\StoreEvent;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| POST /webhooks/stripe (spec 2026-10-27 §5, §9): Stripe's signature is checked on the raw body; a site with no
| webhook secret answers 503; a failure on our side answers 500 and leaves the message to be sent again.
*/

beforeEach(function () {
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_test_51Hook', 'pk_test_51Hook', User::factory()->create(['platform_role' => 'super_admin']));
    $settings->saveWebhook('we_1', 'whsec_site');
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
    [$owner, $this->tenant] = owner();
    $this->tenant->forceFill(['owner_user_id' => $owner->id, 'stripe_customer_id' => 'cus_test_1', 'stripe_livemode' => false])->save();
    $this->stripe->seedSubscription(FakeStripeGateway::subscriptionData(['metadata' => ['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'period' => 'year']]));
});

function sendStripe(string $payload, ?string $header): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($header !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $header;
    }

    return test()->call('POST', '/webhooks/stripe', [], [], [], $server, $payload);
}

function signedSubscriptionUpdate(string $secret = 'whsec_site', string $id = 'evt_hook_1'): array
{
    return FakeStripeGateway::signedEvent('customer.subscription.updated', ['id' => 'sub_test_1', 'object' => 'subscription', 'customer' => 'cus_test_1'], $secret, $id);
}

it('applies a message Stripe signed', function () {
    $signed = signedSubscriptionUpdate();

    sendStripe($signed['payload'], $signed['header'])->assertOk()->assertJson(['outcome' => 'active']);

    expect($this->tenant->fresh()->currentPlan()->key)->toBe('pro');
});

it('refuses a message signed with another secret', function () {
    $signed = signedSubscriptionUpdate('whsec_someone_else');

    sendStripe($signed['payload'], $signed['header'])->assertStatus(400);
    expect($this->tenant->fresh()->currentPlan()->key)->toBe('free');
});

it('refuses a message with no signature', function () {
    sendStripe(signedSubscriptionUpdate()['payload'], null)->assertStatus(400);
});

it('answers 503 while the site has no webhook secret', function () {
    app(CardSettings::class)->clear(User::factory()->create(['platform_role' => 'super_admin']));
    $signed = signedSubscriptionUpdate();

    sendStripe($signed['payload'], $signed['header'])->assertStatus(503);
});

it('answers 500 when Stripe cannot be asked, and applies the message when it comes again', function () {
    $signed = signedSubscriptionUpdate();
    $this->stripe->failNext(new CardUnavailable('down'));

    sendStripe($signed['payload'], $signed['header'])->assertStatus(500);
    expect(StoreEvent::query()->where('event_id', 'evt_hook_1')->sole()->processed_at)->toBeNull()
        ->and($this->tenant->fresh()->currentPlan()->key)->toBe('free');

    sendStripe($signed['payload'], $signed['header'])->assertOk()->assertJson(['outcome' => 'active']);
    expect($this->tenant->fresh()->currentPlan()->key)->toBe('pro');
});

it('needs no session, cookie or form token', function () {
    $signed = signedSubscriptionUpdate();

    sendStripe($signed['payload'], $signed['header'])->assertOk()->assertCookieMissing(config('session.cookie'));
});
