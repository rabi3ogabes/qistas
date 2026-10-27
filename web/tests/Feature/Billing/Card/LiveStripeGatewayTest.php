<?php

use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\Data\SubscriptionCheckout;
use App\Billing\Card\LiveStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Util\ApiVersion;
use Tests\Support\StripeHttpRecorder;

/*
| The live gateway on stripe-php 22 (plan Task 3): what it sends to Stripe for each question, and how it reads Stripe's
| answers into Qistas' terms. Stripe's HTTP layer is replaced by a recorder; nothing leaves the machine.
*/

beforeEach(function () {
    app(CardSettings::class)->saveKeys('sk_test_51LiveGatewayTest', 'pk_test_51LiveGatewayTest', User::factory()->create(['platform_role' => 'super_admin']));
    $this->stripe = new StripeHttpRecorder;
    $this->gateway = new LiveStripeGateway(app(CardSettings::class), $this->stripe);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/** @param  array<string, mixed>  $overrides */
function liveStripeSubscription(array $overrides = []): array
{
    return array_replace([
        'id' => 'sub_live_1', 'object' => 'subscription', 'customer' => 'cus_live_1', 'status' => 'active',
        'cancel_at_period_end' => false, 'trial_end' => null, 'created' => 1793000000, 'schedule' => null, 'latest_invoice' => 'in_live_1',
        'metadata' => ['tenant_id' => 'tenant-1', 'plan' => 'pro', 'period' => 'year'],
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_live_1', 'object' => 'subscription_item', 'current_period_end' => 1824536000,
            'price' => ['id' => 'price_year', 'object' => 'price', 'unit_amount' => 12000, 'currency' => 'usd', 'recurring' => ['interval' => 'year', 'interval_count' => 1]],
        ]]],
    ], $overrides);
}

function liveStripeCheckout(array $overrides = []): SubscriptionCheckout
{
    return new SubscriptionCheckout(...$overrides + [
        'customerId' => 'cus_live_1', 'priceId' => 'price_year', 'trialDays' => 14,
        'metadata' => ['tenant_id' => 'tenant-1', 'plan' => 'pro', 'period' => 'year'],
        'promotionCodes' => true, 'collectTaxId' => false, 'automaticTax' => false,
        'locale' => 'ar', 'returnUrl' => 'https://qistas.test/app/billing/card/return?session_id={CHECKOUT_SESSION_ID}',
    ]);
}

describe('checkout', function () {
    it('opens an elements-mode subscription checkout with the trial, metadata and the idempotency key', function () {
        $this->stripe->on('post', '/v1/checkout/sessions', ['id' => 'cs_1', 'object' => 'checkout.session', 'client_secret' => 'cs_1_secret', 'status' => 'open', 'mode' => 'subscription', 'customer' => 'cus_live_1', 'subscription' => null, 'setup_intent' => null, 'metadata' => ['tenant_id' => 'tenant-1']]);

        $session = $this->gateway->createSubscriptionCheckout(liveStripeCheckout(), 'attempt-key-1');

        $sent = $this->stripe->sent('post', '/v1/checkout/sessions')[0];
        expect($sent['params'])->toMatchArray([
            'ui_mode' => 'elements', 'mode' => 'subscription', 'customer' => 'cus_live_1', 'locale' => 'ar',
            'return_url' => 'https://qistas.test/app/billing/card/return?session_id={CHECKOUT_SESSION_ID}',
            'client_reference_id' => 'tenant-1',
        ])
            ->and($sent['params']['allow_promotion_codes'])->toBe('true')
            ->and($sent['params']['line_items'])->toBe([['price' => 'price_year', 'quantity' => 1]])
            ->and($sent['params']['subscription_data'])->toBe(['metadata' => ['tenant_id' => 'tenant-1', 'plan' => 'pro', 'period' => 'year'], 'trial_period_days' => 14])
            ->and($sent['params']['metadata'])->toBe(['tenant_id' => 'tenant-1', 'plan' => 'pro', 'period' => 'year'])
            ->and($sent['params'])->not->toHaveKey('automatic_tax')
            ->and($sent['params'])->not->toHaveKey('tax_id_collection')
            ->and(StripeHttpRecorder::idempotencyKey($sent))->toBe('attempt-key-1')
            ->and($session->clientSecret)->toBe('cs_1_secret')
            ->and($session->status)->toBe('open');
    });

    it('sends no trial when there is none, and asks for tax details only when a tax option is on', function () {
        $this->stripe->on('post', '/v1/checkout/sessions', ['id' => 'cs_1', 'object' => 'checkout.session', 'client_secret' => 's', 'status' => 'open', 'mode' => 'subscription']);

        $this->gateway->createSubscriptionCheckout(liveStripeCheckout(['trialDays' => null, 'promotionCodes' => false, 'automaticTax' => true, 'collectTaxId' => true]), 'k');

        $params = $this->stripe->sent('post', '/v1/checkout/sessions')[0]['params'];
        expect($params['subscription_data'])->not->toHaveKey('trial_period_days')
            ->and($params)->not->toHaveKey('allow_promotion_codes')
            ->and($params['automatic_tax'])->toBe(['enabled' => 'true'])
            ->and($params['tax_id_collection'])->toBe(['enabled' => 'true'])
            ->and($params['customer_update'])->toBe(['name' => 'auto', 'address' => 'auto'])
            ->and($params['billing_address_collection'])->toBe('required');
    });

    it('opens a setup checkout for a new card', function () {
        $this->stripe->on('post', '/v1/checkout/sessions', ['id' => 'cs_2', 'object' => 'checkout.session', 'client_secret' => 'cs_2_secret', 'status' => 'open', 'mode' => 'setup', 'setup_intent' => 'seti_1']);

        $session = $this->gateway->createSetupCheckout('cus_live_1', 'https://qistas.test/back?session_id={CHECKOUT_SESSION_ID}', 'fr', ['tenant_id' => 'tenant-1', 'purpose' => 'card_change']);

        $params = $this->stripe->sent('post', '/v1/checkout/sessions')[0]['params'];
        expect($params)->toMatchArray(['ui_mode' => 'elements', 'mode' => 'setup', 'customer' => 'cus_live_1', 'locale' => 'fr'])
            ->and($params['payment_method_types'])->toBe(['card'])
            ->and($params['metadata'])->toBe(['tenant_id' => 'tenant-1', 'purpose' => 'card_change'])
            ->and($session->mode)->toBe('setup')
            ->and($session->setupIntentId)->toBe('seti_1');
    });

    it('reads a finished session', function () {
        $this->stripe->on('get', '/v1/checkout/sessions/cs_1', ['id' => 'cs_1', 'object' => 'checkout.session', 'client_secret' => 'cs_1_secret', 'status' => 'complete', 'mode' => 'subscription', 'customer' => 'cus_live_1', 'subscription' => 'sub_live_1', 'metadata' => ['tenant_id' => 'tenant-1']]);

        $session = $this->gateway->session('cs_1');

        expect($session->status)->toBe('complete')->and($session->subscriptionId)->toBe('sub_live_1')
            ->and($session->customerId)->toBe('cus_live_1')->and($session->metadata)->toBe(['tenant_id' => 'tenant-1']);
    });
});

describe('prices and customers', function () {
    it('reuses the active price with the lookup key', function () {
        $this->stripe->on('get', '/v1/prices', ['object' => 'list', 'data' => [['id' => 'price_existing', 'object' => 'price']]]);

        $id = $this->gateway->priceFor('qistas_pro_year_usd_12000', 'pro', 'Qistas Pro', 12000, 'USD', 'year');

        expect($id)->toBe('price_existing')
            ->and($this->stripe->sent('get', '/v1/prices')[0]['params'])->toMatchArray(['lookup_keys' => ['qistas_pro_year_usd_12000'], 'active' => true])
            ->and($this->stripe->sent('post', '/v1/prices'))->toBe([]);
    });

    it('creates the product and an inclusive price when none has the lookup key', function () {
        $this->stripe->on('get', '/v1/prices', ['object' => 'list', 'data' => []])
            ->on('get', '/v1/products/search', ['object' => 'search_result', 'data' => []])
            ->on('post', '/v1/products', ['id' => 'prod_1', 'object' => 'product'])
            ->on('post', '/v1/prices', ['id' => 'price_new', 'object' => 'price']);

        $id = $this->gateway->priceFor('qistas_pro_month_usd_1200', 'pro', 'Qistas Pro', 1200, 'USD', 'month');

        expect($id)->toBe('price_new')
            ->and($this->stripe->sent('get', '/v1/products/search')[0]['params']['query'])->toBe("metadata['qistas_plan']:'pro' AND active:'true'")
            ->and($this->stripe->sent('post', '/v1/products')[0]['params'])->toBe(['name' => 'Qistas Pro', 'metadata' => ['qistas_plan' => 'pro']])
            ->and($this->stripe->sent('post', '/v1/prices')[0]['params'])->toBe([
                'product' => 'prod_1', 'unit_amount' => 1200, 'currency' => 'usd', 'recurring' => ['interval' => 'month'],
                'tax_behavior' => 'inclusive', 'lookup_key' => 'qistas_pro_month_usd_1200', 'metadata' => ['qistas_plan' => 'pro'],
            ]);
    });

    it('uses the product it finds instead of making another', function () {
        $this->stripe->on('get', '/v1/prices', ['object' => 'list', 'data' => []])
            ->on('get', '/v1/products/search', ['object' => 'search_result', 'data' => [['id' => 'prod_found', 'object' => 'product']]])
            ->on('post', '/v1/prices', ['id' => 'price_new', 'object' => 'price']);

        $this->gateway->priceFor('k', 'pro', 'Qistas Pro', 1200, 'USD', 'month');

        expect($this->stripe->sent('post', '/v1/products'))->toBe([])
            ->and($this->stripe->sent('post', '/v1/prices')[0]['params']['product'])->toBe('prod_found');
    });

    it('creates a customer once per idempotency key', function () {
        $this->stripe->on('post', '/v1/customers', ['id' => 'cus_new', 'object' => 'customer']);

        $id = $this->gateway->createCustomer(['name' => 'Al-Fares Electronics', 'email' => 'owner@example.test', 'locale' => 'ar', 'tenant_id' => 'tenant-1'], 'customer-key');

        $sent = $this->stripe->sent('post', '/v1/customers')[0];
        expect($id)->toBe('cus_new')
            ->and($sent['params'])->toBe(['name' => 'Al-Fares Electronics', 'email' => 'owner@example.test', 'preferred_locales' => ['ar'], 'metadata' => ['tenant_id' => 'tenant-1']])
            ->and(StripeHttpRecorder::idempotencyKey($sent))->toBe('customer-key');
    });
});

describe('subscriptions', function () {
    it('reads the period end, price and interval from the subscription item', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['status' => 'trialing', 'trial_end' => 1794200000]));

        $subscription = $this->gateway->subscription('sub_live_1');

        expect($subscription->status)->toBe('trialing')
            ->and($subscription->customerId)->toBe('cus_live_1')
            ->and($subscription->periodEnd?->getTimestamp())->toBe(1824536000)
            ->and($subscription->trialEnd?->getTimestamp())->toBe(1794200000)
            ->and($subscription->priceId)->toBe('price_year')
            ->and($subscription->interval)->toBe('year')
            ->and($subscription->amount)->toBe('120.0000')
            ->and($subscription->currency)->toBe('usd')
            ->and($subscription->metadata)->toBe(['tenant_id' => 'tenant-1', 'plan' => 'pro', 'period' => 'year'])
            ->and($subscription->scheduledInterval)->toBeNull()
            ->and($subscription->latestInvoiceId)->toBe('in_live_1');
    });

    it('reads the price a schedule moves to at the end of the period', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['schedule' => 'sub_sched_1']))
            ->on('get', '/v1/subscription_schedules/sub_sched_1', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'phases' => [
                ['start_date' => 1793000000, 'end_date' => 1824536000, 'items' => [['price' => 'price_year', 'quantity' => 1]]],
                ['start_date' => 1824536000, 'end_date' => 1827214400, 'items' => [['price' => 'price_month', 'quantity' => 1]]],
            ]])
            ->on('get', '/v1/prices/price_month', ['id' => 'price_month', 'object' => 'price', 'recurring' => ['interval' => 'month']]);

        $subscription = $this->gateway->subscription('sub_live_1');

        expect($subscription->scheduleId)->toBe('sub_sched_1')
            ->and($subscription->scheduledPriceId)->toBe('price_month')
            ->and($subscription->scheduledInterval)->toBe('month');
    });

    it('turns renewal off and on', function () {
        $this->stripe->on('post', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['cancel_at_period_end' => true]));

        $subscription = $this->gateway->setCancelAtPeriodEnd('sub_live_1', true);

        expect($this->stripe->sent('post', '/v1/subscriptions/sub_live_1')[0]['params'])->toBe(['cancel_at_period_end' => 'true'])
            ->and($subscription->cancelAtPeriodEnd)->toBeTrue();
    });

    it('moves to another price now, charging the difference at once', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['items' => ['object' => 'list', 'data' => [[
            'id' => 'si_live_1', 'object' => 'subscription_item', 'current_period_end' => 1795600000,
            'price' => ['id' => 'price_month', 'object' => 'price', 'unit_amount' => 1200, 'currency' => 'usd', 'recurring' => ['interval' => 'month']],
        ]]]]))
            ->on('post', '/v1/subscriptions/sub_live_1', liveStripeSubscription());

        $subscription = $this->gateway->changePriceNow('sub_live_1', 'price_year');

        expect($this->stripe->sent('post', '/v1/subscriptions/sub_live_1')[0]['params'])->toBe([
            'items' => [['id' => 'si_live_1', 'price' => 'price_year']],
            'proration_behavior' => 'always_invoice', 'payment_behavior' => 'error_if_incomplete', 'cancel_at_period_end' => 'false',
        ])->and($subscription->interval)->toBe('year');
    });

    it('releases a pending schedule before moving to another price now', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['schedule' => 'sub_sched_1']), once: true)
            ->on('post', '/v1/subscription_schedules/sub_sched_1/release', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule'])
            ->on('post', '/v1/subscriptions/sub_live_1', liveStripeSubscription());

        $this->gateway->changePriceNow('sub_live_1', 'price_year');

        expect($this->stripe->sent('post', '/v1/subscription_schedules/sub_sched_1/release'))->toHaveCount(1);
    });

    it('schedules a price for the end of the period paid', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(), once: true)
            ->on('post', '/v1/subscription_schedules', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'phases' => [
                ['start_date' => 1793000000, 'end_date' => 1824536000, 'items' => [['price' => 'price_year', 'quantity' => 1]]],
            ]])
            ->on('post', '/v1/subscription_schedules/sub_sched_1', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule'])
            ->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['schedule' => 'sub_sched_1']))
            ->on('get', '/v1/subscription_schedules/sub_sched_1', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'phases' => [
                ['start_date' => 1793000000, 'end_date' => 1824536000, 'items' => [['price' => 'price_year', 'quantity' => 1]]],
                ['start_date' => 1824536000, 'end_date' => 1827214400, 'items' => [['price' => 'price_month', 'quantity' => 1]]],
            ]])
            ->on('get', '/v1/prices/price_month', ['id' => 'price_month', 'object' => 'price', 'recurring' => ['interval' => 'month']]);

        $subscription = $this->gateway->schedulePriceChange('sub_live_1', 'price_month');

        expect($this->stripe->sent('post', '/v1/subscription_schedules')[0]['params'])->toBe(['from_subscription' => 'sub_live_1'])
            ->and($this->stripe->sent('post', '/v1/subscription_schedules/sub_sched_1')[0]['params'])->toBe([
                'end_behavior' => 'release',
                'phases' => [
                    ['items' => [['price' => 'price_year', 'quantity' => 1]], 'start_date' => 1793000000, 'end_date' => 1824536000, 'proration_behavior' => 'none'],
                    ['items' => [['price' => 'price_month', 'quantity' => 1]], 'duration' => ['interval' => 'month', 'interval_count' => 1], 'proration_behavior' => 'none'],
                ],
            ])
            ->and($subscription->scheduledInterval)->toBe('month');
    });

    it('previews moving to another price', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription())
            ->on('post', '/v1/invoices/create_preview', ['object' => 'invoice', 'amount_due' => 10800, 'currency' => 'usd']);

        $preview = $this->gateway->previewPriceChange('sub_live_1', 'price_month');

        expect($this->stripe->sent('post', '/v1/invoices/create_preview')[0]['params'])->toBe([
            'customer' => 'cus_live_1', 'subscription' => 'sub_live_1',
            'subscription_details' => ['items' => [['id' => 'si_live_1', 'price' => 'price_month']], 'proration_behavior' => 'always_invoice'],
        ])->and($preview->amountDue)->toBe('108.0000')->and($preview->currency)->toBe('usd');
    });

    it('says what the next renewal charges, and nothing when renewal is off', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(), once: true)
            ->on('post', '/v1/invoices/create_preview', ['object' => 'invoice', 'amount_due' => 9600, 'currency' => 'usd'])
            ->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['cancel_at_period_end' => true]));

        $next = $this->gateway->upcoming('sub_live_1');

        expect($next?->amountDue)->toBe('96.0000')
            ->and($next?->nextCharge->getTimestamp())->toBe(1824536000)
            ->and($this->stripe->sent('post', '/v1/invoices/create_preview')[0]['params'])->toBe(['customer' => 'cus_live_1', 'subscription' => 'sub_live_1'])
            ->and($this->gateway->upcoming('sub_live_1'))->toBeNull();
    });

    it('cancels now and refunds the latest payment', function () {
        $this->stripe->on('delete', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['status' => 'canceled']))
            ->on('get', '/v1/invoices/in_live_1', ['id' => 'in_live_1', 'object' => 'invoice', 'customer' => 'cus_live_1', 'payments' => ['object' => 'list', 'data' => [
                ['id' => 'inpay_1', 'object' => 'invoice_payment', 'status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_1']],
            ]]])
            ->on('post', '/v1/refunds', ['id' => 're_1', 'object' => 'refund'])
            ->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription());

        $this->gateway->cancelNow('sub_live_1', true);

        $order = array_map(fn ($r) => $r['method'].' '.$r['path'], $this->stripe->requests);
        expect(array_search('delete /v1/subscriptions/sub_live_1', $order, true))->toBeLessThan(array_search('post /v1/refunds', $order, true))
            ->and($this->stripe->sent('delete', '/v1/subscriptions/sub_live_1')[0]['params'])->toBe(['prorate' => 'false'])
            ->and($this->stripe->sent('post', '/v1/refunds')[0]['params'])->toBe(['payment_intent' => 'pi_1']);
    });

    it('cancels now and refunds only the unused time of the latest payment', function (int $elapsed, ?int $refunded) {
        // A year of 31,536,000 seconds, paid 120.00; cancelled $elapsed seconds into it.
        $this->travelTo(CarbonImmutable::createFromTimestamp(1793000000 + $elapsed));
        $item = ['id' => 'si_live_1', 'object' => 'subscription_item', 'current_period_start' => 1793000000, 'current_period_end' => 1824536000,
            'price' => ['id' => 'price_year', 'object' => 'price', 'unit_amount' => 12000, 'currency' => 'usd', 'recurring' => ['interval' => 'year', 'interval_count' => 1]]];
        $this->stripe->on('delete', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['status' => 'canceled', 'items' => ['object' => 'list', 'data' => [$item]]]))
            ->on('get', '/v1/invoices/in_live_1', ['id' => 'in_live_1', 'object' => 'invoice', 'customer' => 'cus_live_1', 'payments' => ['object' => 'list', 'data' => [
                ['id' => 'inpay_1', 'object' => 'invoice_payment', 'status' => 'paid', 'amount_paid' => 12000, 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_1']],
            ]]])
            ->on('post', '/v1/refunds', ['id' => 're_1', 'object' => 'refund']);

        $this->gateway->cancelNow('sub_live_1', true, true);

        $refunds = $this->stripe->sent('post', '/v1/refunds');
        expect($refunds === [] ? null : $refunds[0]['params'])->toBe($refunded === null ? null : ['payment_intent' => 'pi_1', 'amount' => $refunded]);
    })->with([
        'a quarter used' => [7884000, 9000],
        'the first day' => [86400, 11967],
        'the period over' => [31536000, null],
    ]);

    it('makes the card a setup session saved the one the subscription is charged to', function () {
        $this->stripe->on('get', '/v1/setup_intents/seti_1', ['id' => 'seti_1', 'object' => 'setup_intent', 'payment_method' => 'pm_new', 'customer' => 'cus_live_1'])
            ->on('post', '/v1/subscriptions/sub_live_1', liveStripeSubscription())
            ->on('post', '/v1/customers/cus_live_1', ['id' => 'cus_live_1', 'object' => 'customer']);

        $this->gateway->useSetupPaymentMethod('seti_1', 'sub_live_1');

        expect($this->stripe->sent('post', '/v1/subscriptions/sub_live_1')[0]['params'])->toBe(['default_payment_method' => 'pm_new'])
            ->and($this->stripe->sent('post', '/v1/customers/cus_live_1')[0]['params'])->toBe(['invoice_settings' => ['default_payment_method' => 'pm_new']]);
    });

    it('reads the card the subscription is charged to', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription(['default_payment_method' => [
            'id' => 'pm_1', 'object' => 'payment_method', 'type' => 'card',
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030, 'wallet' => ['type' => 'apple_pay']],
        ]]));

        $card = $this->gateway->paymentMethod('sub_live_1');

        expect($card?->brand)->toBe('visa')->and($card?->last4)->toBe('4242')->and($card?->expMonth)->toBe(8)
            ->and($card?->expYear)->toBe(2030)->and($card?->wallet)->toBe('apple_pay');
    });
});

describe('invoices', function () {
    it('reads an invoice with its subscription and the payment the bank wants confirmed', function () {
        $this->stripe->on('get', '/v1/invoices/in_live_1', [
            'id' => 'in_live_1', 'object' => 'invoice', 'number' => 'QIS-0001', 'customer' => 'cus_live_1', 'created' => 1793000000,
            'amount_paid' => 0, 'amount_due' => 12000, 'currency' => 'usd', 'status' => 'open', 'billing_reason' => 'subscription_cycle',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_live_1']],
            'confirmation_secret' => ['client_secret' => 'pi_1_secret_x', 'type' => 'payment_intent'],
            'payments' => ['object' => 'list', 'data' => [['id' => 'inpay_1', 'object' => 'invoice_payment', 'status' => 'open', 'payment' => ['type' => 'payment_intent', 'payment_intent' => ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'requires_action']]]]],
        ]);

        $invoice = $this->gateway->invoice('in_live_1');

        expect($invoice->number)->toBe('QIS-0001')->and($invoice->customerId)->toBe('cus_live_1')
            ->and($invoice->amount)->toBe('120.0000')->and($invoice->status)->toBe('open')
            ->and($invoice->billingReason)->toBe('subscription_cycle')->and($invoice->subscriptionId)->toBe('sub_live_1')
            ->and($invoice->paymentIntentId)->toBe('pi_1')->and($invoice->paymentClientSecret)->toBe('pi_1_secret_x')
            ->and($invoice->paymentStatus)->toBe('requires_action');
    });

    it('lists a customer\'s invoices, newest first as Stripe gives them', function () {
        $this->stripe->on('get', '/v1/invoices', ['object' => 'list', 'data' => [
            ['id' => 'in_2', 'object' => 'invoice', 'number' => 'QIS-0002', 'customer' => 'cus_live_1', 'created' => 1795600000, 'amount_paid' => 12000, 'amount_due' => 12000, 'currency' => 'usd', 'status' => 'paid', 'billing_reason' => 'subscription_cycle'],
            ['id' => 'in_1', 'object' => 'invoice', 'number' => 'QIS-0001', 'customer' => 'cus_live_1', 'created' => 1793000000, 'amount_paid' => 0, 'amount_due' => 0, 'currency' => 'usd', 'status' => 'paid', 'billing_reason' => 'subscription_create'],
        ]]);

        $invoices = $this->gateway->invoices('cus_live_1', 24);

        expect(array_map(fn ($i) => $i->id, $invoices))->toBe(['in_2', 'in_1'])
            ->and($invoices[0]->amount)->toBe('120.0000')
            ->and($this->stripe->sent('get', '/v1/invoices')[0]['params'])->toMatchArray(['customer' => 'cus_live_1', 'limit' => 24]);
    });

    it('downloads an invoice PDF through the link Stripe gives', function () {
        Http::fake(['https://pay.stripe.com/*' => Http::response('%PDF-1.7 receipt', 200, ['Content-Type' => 'application/pdf'])]);
        $this->stripe->on('get', '/v1/invoices/in_live_1', ['id' => 'in_live_1', 'object' => 'invoice', 'customer' => 'cus_live_1', 'invoice_pdf' => 'https://pay.stripe.com/invoice/acct_1/in_live_1/pdf']);

        expect($this->gateway->invoicePdf('in_live_1'))->toBe('%PDF-1.7 receipt');
    });

    it('refuses a PDF for an invoice not finalised yet', function () {
        $this->stripe->on('get', '/v1/invoices/in_live_1', ['id' => 'in_live_1', 'object' => 'invoice', 'customer' => 'cus_live_1', 'invoice_pdf' => null]);

        expect(fn () => $this->gateway->invoicePdf('in_live_1'))->toThrow(CardRefused::class);
    });
});

describe('setup', function () {
    it('reads the account', function () {
        $this->stripe->on('get', '/v1/account', ['id' => 'acct_1', 'object' => 'account', 'country' => 'AE', 'default_currency' => 'aed', 'charges_enabled' => true, 'business_profile' => ['name' => 'Qistas Ltd'], 'settings' => ['dashboard' => ['display_name' => 'Qistas']]]);

        $account = $this->gateway->account();

        expect($account->id)->toBe('acct_1')->and($account->name)->toBe('Qistas Ltd')->and($account->country)->toBe('AE')
            ->and($account->currency)->toBe('aed')->and($account->chargesEnabled)->toBeTrue()->and($account->livemode)->toBeFalse();
    });

    it('creates the webhook pinned to the library\'s API version', function () {
        $this->stripe->on('post', '/v1/webhook_endpoints', ['id' => 'we_1', 'object' => 'webhook_endpoint', 'secret' => 'whsec_new']);

        $webhook = $this->gateway->createWebhook('https://qistas.test/webhooks/stripe', ['invoice.paid', 'customer.subscription.updated']);

        expect($webhook)->toBe(['id' => 'we_1', 'secret' => 'whsec_new'])
            ->and($this->stripe->sent('post', '/v1/webhook_endpoints')[0]['params'])->toBe([
                'url' => 'https://qistas.test/webhooks/stripe', 'enabled_events' => ['invoice.paid', 'customer.subscription.updated'],
                'api_version' => ApiVersion::CURRENT, 'description' => 'Qistas: Pro by card',
            ]);
    });

    it('deletes the webhook', function () {
        $this->stripe->on('delete', '/v1/webhook_endpoints/we_1', ['id' => 'we_1', 'object' => 'webhook_endpoint', 'deleted' => true]);

        $this->gateway->deleteWebhook('we_1');

        expect($this->stripe->sent('delete', '/v1/webhook_endpoints/we_1'))->toHaveCount(1);
    });

    it('registers the domain for wallets once', function () {
        $this->stripe->on('get', '/v1/payment_method_domains', ['object' => 'list', 'data' => [['id' => 'pmd_1', 'object' => 'payment_method_domain', 'domain_name' => 'qistas.test']]]);

        $this->gateway->registerDomain('qistas.test');

        expect($this->stripe->sent('post', '/v1/payment_method_domains'))->toBe([]);
    });

    it('registers a new domain for wallets', function () {
        $this->stripe->on('get', '/v1/payment_method_domains', ['object' => 'list', 'data' => []])
            ->on('post', '/v1/payment_method_domains', ['id' => 'pmd_1', 'object' => 'payment_method_domain']);

        $this->gateway->registerDomain('qistas.test');

        expect($this->stripe->sent('post', '/v1/payment_method_domains')[0]['params'])->toBe(['domain_name' => 'qistas.test']);
    });
});

it('is the gateway the application uses once a secret key is saved', function () {
    expect(app(StripeGateway::class))->toBeInstanceOf(LiveStripeGateway::class);
});

describe('failures', function () {
    it('reports Stripe being out of reach as unavailable', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', ApiConnectionException::factory('Could not connect to Stripe'));

        expect(fn () => $this->gateway->subscription('sub_live_1'))->toThrow(CardUnavailable::class);
    });

    it('reports a fault on Stripe\'s side as unavailable', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', ['error' => ['type' => 'api_error', 'message' => 'Something went wrong']], 500);

        expect(fn () => $this->gateway->subscription('sub_live_1'))->toThrow(CardUnavailable::class);
    });

    it('passes a declined card on with Stripe\'s message', function () {
        $this->stripe->on('post', '/v1/subscriptions/sub_live_1', ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']], 402);

        expect(fn () => $this->gateway->setCancelAtPeriodEnd('sub_live_1', false))->toThrow(CardRefused::class, 'Your card was declined.');
    });

    it('reports a refused key as unavailable', function () {
        $this->stripe->on('get', '/v1/account', ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401);

        expect(fn () => $this->gateway->account())->toThrow(CardUnavailable::class);
    });

    it('sends every call with the saved secret key and the pinned API version', function () {
        $this->stripe->on('get', '/v1/subscriptions/sub_live_1', liveStripeSubscription());

        $this->gateway->subscription('sub_live_1');

        $headers = implode("\n", $this->stripe->requests[0]['headers']);
        expect($headers)->toContain('Bearer sk_test_51LiveGatewayTest')->and($headers)->toContain('Stripe-Version: '.ApiVersion::CURRENT);
    });
});
