<?php

use App\Billing\Card\CardManagement;
use App\Billing\Card\CardSettings;
use App\Billing\Card\Data\CardInvoice;
use App\Billing\Card\Data\CardPreview;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Billing\Card\StripePrices;
use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| Managing a card subscription inside Qistas (spec 2026-10-27 §4.3, §5): the card on file and a new one, switching
| between monthly and yearly, cancelling and resuming, receipts, and confirming a payment the bank holds. The owner only,
| and only the workspace's own subscription and invoices.
*/

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    switchOn(Feature::CardBilling);
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_test_51Manage', 'pk_test_51Manage', User::factory()->create(['platform_role' => 'super_admin']));
    $settings->saveWebhook('we_1', 'whsec_manage');
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
    [$this->owner, $this->tenant] = owner();
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id, 'stripe_customer_id' => 'cus_test_1', 'stripe_livemode' => false])->save();
});

/** The workspace pays for Pro by card, $interval-ly, and Stripe holds the subscription. */
function payingByCard(object $test, string $interval = 'year', array $overrides = []): void
{
    $end = $interval === 'year' ? CarbonImmutable::parse('2027-10-27 09:00:00') : CarbonImmutable::parse('2026-11-27 09:00:00');
    $test->stripe->seedSubscription(FakeStripeGateway::subscriptionData($overrides + [
        'interval' => $interval, 'amount' => $interval === 'year' ? '120.0000' : '12.0000', 'periodEnd' => $end,
        'metadata' => ['tenant_id' => $test->tenant->id, 'plan' => 'pro', 'period' => $interval],
    ]));
    $test->tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', $end, [
        'source' => 'stripe', 'stripe_subscription_id' => 'sub_test_1', 'period' => $interval, 'auto_renew' => true,
    ]);
}

function cardInvoice(array $overrides = []): CardInvoice
{
    return new CardInvoice(...$overrides + [
        'id' => 'in_1', 'number' => 'QIS-0001', 'customerId' => 'cus_test_1', 'created' => CarbonImmutable::parse('2026-10-27 09:00:00'),
        'amount' => '120.0000', 'currency' => 'usd', 'status' => 'paid', 'billingReason' => 'subscription_create',
        'subscriptionId' => 'sub_test_1', 'paymentIntentId' => 'pi_1', 'paymentClientSecret' => null, 'paymentStatus' => 'succeeded',
    ]);
}

describe('who may manage it', function () {
    it('is the owner only', function (string $method, string $route, array $body) {
        payingByCard($this);
        $manager = memberAs('manager', $this->tenant);
        $manager->forceFill(['current_tenant_id' => $this->tenant->id])->save();

        $this->actingAs($manager)->json($method, route($route, ['invoice' => 'in_1']), $body)->assertForbidden();
    })->with([
        ['POST', 'app.billing.card.setup', []],
        ['GET', 'app.billing.card.setup-return', ['session_id' => 'cs_1']],
        ['GET', 'app.billing.card.switch.preview', ['period' => 'month']],
        ['POST', 'app.billing.card.switch', ['period' => 'month']],
        ['POST', 'app.billing.card.cancel', []],
        ['POST', 'app.billing.card.resume', []],
        ['POST', 'app.billing.card.confirm', []],
        ['GET', 'app.billing.card.receipt', []],
    ]);

    it('refuses when there is no card subscription to change', function () {
        $this->actingAs($this->owner)->post(route('app.billing.card.cancel'))
            ->assertRedirect(route('app.billing'))
            ->assertSessionHasErrors(['card' => 'There is no card subscription to change.']);
        $this->actingAs($this->owner)->postJson(route('app.billing.card.setup'))->assertUnprocessable()->assertJsonPath('message', 'There is no card subscription to change.');
    });
});

describe('the overview', function () {
    it('reads where the subscription stands from Stripe', function () {
        payingByCard($this);
        $this->stripe->setUpcoming('sub_test_1', new CardPreview('96.0000', 'usd', CarbonImmutable::parse('2027-10-27 09:00:00')));

        $overview = app(CardManagement::class)->overview($this->tenant);

        expect($overview['status'])->toBe('active')
            ->and($overview['period'])->toBe('year')
            ->and($overview['until']?->toDateString())->toBe('2027-10-27')
            ->and($overview['next_amount'])->toBe('96.0000')
            ->and($overview['currency'])->toBe('usd')
            ->and($overview['cancel_at_period_end'])->toBeFalse()
            ->and($overview['card']?->last4)->toBe('4242')
            ->and($overview['needs_action'])->toBeFalse()
            ->and($overview['scheduled_period'])->toBeNull();
    });

    it('is read from Stripe once a minute, and again after any change', function () {
        payingByCard($this);
        $manage = app(CardManagement::class);

        $manage->overview($this->tenant);
        $manage->overview($this->tenant);
        expect($this->stripe->calls('upcoming'))->toHaveCount(1);

        $manage->cancel($this->tenant, null, null, $this->owner);
        $manage->overview($this->tenant);
        expect($this->stripe->calls('upcoming'))->toHaveCount(2);
    });

    it('has none for a workspace that does not pay by card', function () {
        expect(app(CardManagement::class)->overview($this->tenant))->toBeNull();
    });

    it('reads the same again from a cache that keeps no PHP objects, as production’s does, and never keeps the card there', function () {
        config(['cache.serializable_classes' => false]);
        Cache::setDefaultDriver('database');
        payingByCard($this, 'year', ['status' => 'trialing', 'trialEnd' => CarbonImmutable::parse('2026-11-10 09:00:00')]);
        $manage = app(CardManagement::class);

        $first = $manage->overview($this->tenant);
        $again = $manage->overview($this->tenant);

        expect($again)->toEqual($first)
            ->and($again['until']?->toDateString())->toBe('2027-10-27')
            ->and($again['trial_end']?->toDateString())->toBe('2026-11-10')
            ->and($again['card']?->last4)->toBe('4242')
            ->and($this->stripe->calls('upcoming'))->toHaveCount(1)
            ->and((string) DB::table('cache')->value('value'))->not->toContain('4242');
        $this->actingAs($this->owner)->get(route('app.billing'))->assertOk();
        $this->actingAs($this->owner)->get(route('app.billing'))->assertOk()->assertSee('4242');
    });
});

describe('switching period', function () {
    it('moves a monthly subscription to yearly now, at the yearly price', function () {
        payingByCard($this, 'month');

        $this->actingAs($this->owner)->post(route('app.billing.card.switch'), ['period' => 'year'])
            ->assertRedirect(route('app.billing'))->assertSessionHas('status');

        [$subscriptionId, $priceId] = $this->stripe->calls('changePriceNow')[0];
        expect($subscriptionId)->toBe('sub_test_1')
            ->and($this->stripe->price($priceId)['lookup_key'])->toBe('qistas_pro_year_usd_12000')
            ->and($this->tenant->fresh()->subscription()->first()->period)->toBe('year')
            ->and(AuditLog::where('action', 'billing.card_period_switched')->where('tenant_id', $this->tenant->id)->value('changes'))->toBe(['from' => 'month', 'to' => 'year', 'when' => 'now']);
    });

    it('previews the move to yearly with Stripe\'s own amount', function () {
        payingByCard($this, 'month');
        $yearly = app(StripePrices::class)->for(Plan::where('key', 'pro')->sole(), 'year');
        $this->stripe->setPreview($yearly, new CardPreview('108.0000', 'usd', CarbonImmutable::now()));

        $this->actingAs($this->owner)->getJson(route('app.billing.card.switch.preview', ['period' => 'year']))
            ->assertOk()
            ->assertJson(['amount_due' => '108.0000', 'currency' => 'usd', 'when' => 'now', 'date' => '2026-10-27']);
    });

    it('moves a yearly subscription to monthly at the end of the paid year', function () {
        payingByCard($this, 'year');

        $this->actingAs($this->owner)->post(route('app.billing.card.switch'), ['period' => 'month'])->assertRedirect(route('app.billing'));

        [$subscriptionId, $priceId] = $this->stripe->calls('schedulePriceChange')[0];
        expect($subscriptionId)->toBe('sub_test_1')
            ->and($this->stripe->price($priceId)['lookup_key'])->toBe('qistas_pro_month_usd_1200')
            ->and($this->stripe->calls('changePriceNow'))->toBe([])
            ->and(app(CardManagement::class)->overview($this->tenant)['scheduled_period'])->toBe('month');
    });

    it('previews the move to monthly as starting when the year ends', function () {
        payingByCard($this, 'year');

        $this->actingAs($this->owner)->getJson(route('app.billing.card.switch.preview', ['period' => 'month']))
            ->assertOk()
            ->assertJson(['amount_due' => '12.0000', 'currency' => 'USD', 'when' => 'period_end', 'date' => '2027-10-27']);
    });

    it('keeps renewing yearly when the owner switches back before the year ends', function () {
        payingByCard($this, 'year');
        $this->actingAs($this->owner)->post(route('app.billing.card.switch'), ['period' => 'month']);

        $this->actingAs($this->owner)->post(route('app.billing.card.switch'), ['period' => 'year'])->assertRedirect(route('app.billing'));

        expect($this->stripe->calls('releaseSchedule'))->toHaveCount(1)
            ->and(app(CardManagement::class)->overview($this->tenant)['scheduled_period'])->toBeNull();
    });

    it('refuses an unknown period', function () {
        payingByCard($this);

        $this->actingAs($this->owner)->post(route('app.billing.card.switch'), ['period' => 'week'])->assertSessionHasErrors('period');
    });
});

describe('cancelling and resuming', function () {
    it('stops renewal while a switch is scheduled, dropping the switch first (Stripe refuses otherwise)', function (string $who) {
        payingByCard($this, 'year', ['scheduleId' => 'sub_sched_1', 'scheduledPriceId' => 'price_test_pro_month', 'scheduledInterval' => 'month']);
        $manage = app(CardManagement::class);

        $who === 'owner'
            ? $manage->cancel($this->tenant, null, null, $this->owner)
            : $manage->cancelForPlatform($this->tenant, 'period_end', User::factory()->create(['platform_role' => 'super_admin']));

        $overview = $manage->overview($this->tenant);
        expect($this->stripe->calls('releaseSchedule'))->toBe([['sub_test_1']])
            ->and($this->stripe->calls('setCancelAtPeriodEnd'))->toBe([['sub_test_1', true]])
            ->and($overview['cancel_at_period_end'])->toBeTrue()
            ->and($overview['scheduled_period'])->toBeNull()
            ->and($this->tenant->fresh()->subscription()->first()->auto_renew)->toBeFalse();
    })->with(['owner', 'platform']);

    it('turns renewal off, keeps Pro to the end and records the reason', function () {
        payingByCard($this);

        $this->actingAs($this->owner)->post(route('app.billing.card.cancel'), ['reason' => 'too_expensive', 'details' => 'Too much for a small shop.'])
            ->assertRedirect(route('app.billing'))
            ->assertSessionHas('status', __('Pro stays until :date. You won\'t be charged again.', ['date' => '27 Oct 2027']));

        expect($this->stripe->calls('setCancelAtPeriodEnd'))->toBe([['sub_test_1', true]])
            ->and($this->tenant->fresh()->subscription()->first()->auto_renew)->toBeFalse()
            ->and($this->tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and(AuditLog::where('action', 'billing.card_cancelled')->value('changes'))->toBe(['reason' => 'too_expensive', 'details' => 'Too much for a small shop.']);
    });

    it('refuses an unknown reason and over-long details', function (array $body, string $field) {
        payingByCard($this);

        $this->actingAs($this->owner)->post(route('app.billing.card.cancel'), $body)->assertSessionHasErrors($field);
    })->with([
        [['reason' => 'bored'], 'reason'],
        [['details' => str_repeat('a', 501)], 'details'],
    ]);

    it('turns renewal back on before the end', function () {
        payingByCard($this, 'year', ['cancelAtPeriodEnd' => true]);

        $this->actingAs($this->owner)->post(route('app.billing.card.resume'))->assertRedirect(route('app.billing'))->assertSessionHas('status');

        expect($this->stripe->calls('setCancelAtPeriodEnd'))->toBe([['sub_test_1', false]])
            ->and($this->tenant->fresh()->subscription()->first()->auto_renew)->toBeTrue()
            ->and(AuditLog::where('action', 'billing.card_resumed')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    });
});

describe('the card', function () {
    it('opens a setup checkout for a new card', function () {
        payingByCard($this);

        $response = $this->actingAs($this->owner)->postJson(route('app.billing.card.setup'))->assertOk();

        [$customer, $returnUrl, , $metadata] = $this->stripe->calls('createSetupCheckout')[0];
        expect($response->json('client_secret'))->toEndWith('_secret_fake')
            ->and($response->json('publishable_key'))->toBe('pk_test_51Manage')
            ->and($customer)->toBe('cus_test_1')
            ->and($returnUrl)->toBe(route('app.billing.card.setup-return').'?session_id={CHECKOUT_SESSION_ID}')
            ->and($metadata)->toBe(['tenant_id' => $this->tenant->id, 'purpose' => 'card_change']);
    });

    it('makes the new card the one charged, once the setup is done', function () {
        payingByCard($this);
        $session = $this->stripe->createSetupCheckout('cus_test_1', 'https://qistas.test/back', 'en', ['tenant_id' => $this->tenant->id, 'purpose' => 'card_change']);
        $this->stripe->completeSetupSession($session->id);

        $this->actingAs($this->owner)->get(route('app.billing.card.setup-return', ['session_id' => $session->id]))
            ->assertRedirect(route('app.billing'))
            ->assertSessionHas('status', __('Your new card is saved.'));

        expect($this->stripe->calls('useSetupPaymentMethod'))->toBe([[$session->setupIntentId, 'sub_test_1']])
            ->and(AuditLog::where('action', 'billing.card_changed')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
            ->and(app(CardManagement::class)->overview($this->tenant)['card']?->last4)->toBe('4444');
    });

    it('does not apply another workspace\'s setup', function () {
        payingByCard($this);
        $session = $this->stripe->createSetupCheckout('cus_someone_else', 'https://qistas.test/back', 'en', ['purpose' => 'card_change']);
        $this->stripe->completeSetupSession($session->id);

        $this->actingAs($this->owner)->get(route('app.billing.card.setup-return', ['session_id' => $session->id]))->assertNotFound();
        expect($this->stripe->calls('useSetupPaymentMethod'))->toBe([]);
    });

    it('changes nothing for a setup left unfinished', function () {
        payingByCard($this);
        $session = $this->stripe->createSetupCheckout('cus_test_1', 'https://qistas.test/back', 'en', ['purpose' => 'card_change']);

        $this->actingAs($this->owner)->get(route('app.billing.card.setup-return', ['session_id' => $session->id]))
            ->assertRedirect(route('app.billing'))->assertSessionHas('status', __('Your card was not changed.'));
        expect($this->stripe->calls('useSetupPaymentMethod'))->toBe([]);
    });
});

describe('receipts', function () {
    it('lists the workspace\'s charges, newest first, without the trial\'s zero', function () {
        payingByCard($this);
        $this->stripe->seedInvoice(cardInvoice(['id' => 'in_trial', 'number' => 'QIS-0001', 'amount' => '0.0000', 'created' => CarbonImmutable::parse('2026-10-13 09:00:00')]));
        $this->stripe->seedInvoice(cardInvoice(['id' => 'in_paid', 'number' => 'QIS-0002', 'created' => CarbonImmutable::parse('2026-10-27 09:00:00')]));
        $this->stripe->seedInvoice(cardInvoice(['id' => 'in_other', 'number' => 'ZZZ-0001', 'customerId' => 'cus_someone_else']));

        $receipts = app(CardManagement::class)->receipts($this->tenant);

        expect(array_map(fn ($r) => $r->id, $receipts))->toBe(['in_paid'])->and($receipts[0]->amount)->toBe('120.0000');
    });

    it('downloads a receipt as a PDF named after its number', function () {
        payingByCard($this);
        $this->stripe->seedInvoice(cardInvoice(), '%PDF-1.7 the receipt');

        $response = $this->actingAs($this->owner)->get(route('app.billing.card.receipt', ['invoice' => 'in_1']))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="qistas-receipt-QIS-0001.pdf"')
            ->and($response->getContent())->toBe('%PDF-1.7 the receipt');
    });

    it('does not hand out another workspace\'s receipt', function (string $invoice) {
        payingByCard($this);
        $this->stripe->seedInvoice(cardInvoice(['id' => 'in_other', 'customerId' => 'cus_someone_else']));

        $this->actingAs($this->owner)->get(route('app.billing.card.receipt', ['invoice' => $invoice]))->assertNotFound();
    })->with(['in_other', 'in_unknown']);
});

describe('a payment the bank holds', function () {
    it('gives the page what it needs to confirm it', function () {
        payingByCard($this, 'year', ['latestInvoiceId' => 'in_held', 'status' => 'past_due']);
        $this->stripe->seedInvoice(cardInvoice(['id' => 'in_held', 'status' => 'open', 'billingReason' => 'subscription_cycle', 'paymentClientSecret' => 'pi_held_secret', 'paymentStatus' => 'requires_action']));

        $this->actingAs($this->owner)->postJson(route('app.billing.card.confirm'))
            ->assertOk()->assertJson(['client_secret' => 'pi_held_secret', 'publishable_key' => 'pk_test_51Manage']);
        expect(app(CardManagement::class)->overview($this->tenant)['needs_action'])->toBeTrue();
    });

    it('has nothing to confirm when the bank holds nothing', function () {
        payingByCard($this, 'year', ['latestInvoiceId' => 'in_paid']);
        $this->stripe->seedInvoice(cardInvoice(['id' => 'in_paid']));

        $this->actingAs($this->owner)->postJson(route('app.billing.card.confirm'))->assertUnprocessable();
    });
});
