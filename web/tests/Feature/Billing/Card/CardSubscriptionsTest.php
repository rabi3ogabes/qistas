<?php

use App\Billing\Card\CardSettings;
use App\Billing\Card\CardSubscriptions;
use App\Billing\Card\Data\CardEvent;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\StoreEvent;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| Following Stripe (spec 2026-10-27 §6, §7, §9). Every message is applied once, always by asking Stripe what is true
| now; the workspace comes from the Stripe customer Qistas stored, never from the message alone; a second paid
| subscription is cancelled and refunded; the owner hears about trials ending, renewals, failures and the end.
*/

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    app(CardSettings::class)->saveKeys('sk_test_51Subs', 'pk_test_51Subs', User::factory()->create(['platform_role' => 'super_admin']));
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
    [$this->owner, $this->tenant] = owner(['name' => 'Al-Fares Electronics']);
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id, 'stripe_customer_id' => 'cus_test_1', 'stripe_livemode' => false])->save();
});

/** @param  array<string, mixed>  $object */
function cardEvent(string $type, array $object, ?string $id = null, bool $livemode = false): CardEvent
{
    return new CardEvent($id ?? 'evt_'.bin2hex(random_bytes(5)), $type, $object, $livemode, CarbonImmutable::now());
}

/** Stripe now holds this subscription for the test workspace's customer. */
function stripeHolds(FakeStripeGateway $stripe, Tenant $tenant, array $overrides = []): void
{
    $stripe->seedSubscription(FakeStripeGateway::subscriptionData($overrides + [
        'metadata' => ['tenant_id' => $tenant->id, 'plan' => 'pro', 'period' => 'year'],
    ]));
}

function subscriptionChangedEvent(string $id = 'sub_test_1', string $type = 'customer.subscription.updated', ?string $eventId = null): CardEvent
{
    return cardEvent($type, ['id' => $id, 'object' => 'subscription', 'customer' => 'cus_test_1'], $eventId);
}

function noticesOf(User $user, string $type): int
{
    return AppNotification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)->count();
}

describe('what Stripe says, and the workspace\'s plan', function () {
    it('puts the workspace on the plan Stripe reports', function (array $remote, string $status, string $until, bool $autoRenew) {
        stripeHolds($this->stripe, $this->tenant, $remote);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        $subscription = $this->tenant->fresh()->subscription()->first();
        expect($outcome)->toBe($status)
            ->and($this->tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and($subscription->status)->toBe($status)
            ->and($subscription->current_period_end->toDateString())->toBe($until)
            ->and($subscription->auto_renew)->toBe($autoRenew)
            ->and($subscription->source)->toBe('stripe')
            ->and($subscription->stripe_subscription_id)->toBe('sub_test_1')
            ->and($subscription->period)->toBe('year');
    })->with([
        'in a trial' => [['status' => 'trialing', 'trialEnd' => CarbonImmutable::parse('2026-11-10 09:00:00'), 'periodEnd' => CarbonImmutable::parse('2026-11-10 09:00:00')], 'trialing', '2026-11-10', true],
        'active' => [['status' => 'active', 'periodEnd' => CarbonImmutable::parse('2027-10-27 09:00:00')], 'active', '2027-10-27', true],
        'active, renewal off' => [['status' => 'active', 'cancelAtPeriodEnd' => true, 'periodEnd' => CarbonImmutable::parse('2027-10-27 09:00:00')], 'active', '2027-10-27', false],
        'a renewal failing, Stripe retrying' => [['status' => 'past_due', 'periodEnd' => CarbonImmutable::parse('2027-10-27 09:00:00')], 'past_due', '2027-10-27', true],
    ]);

    it('takes the period from the price Stripe charges', function () {
        stripeHolds($this->stripe, $this->tenant, ['interval' => 'month', 'periodEnd' => CarbonImmutable::parse('2026-11-27 09:00:00')]);

        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect($this->tenant->fresh()->subscription()->first()->period)->toBe('month');
    });

    it('uses up the workspace\'s trial', function (string $status) {
        stripeHolds($this->stripe, $this->tenant, ['status' => $status]);

        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect($this->tenant->fresh()->trial_used_at?->toDateTimeString())->toBe('2026-10-27 09:00:00');
    })->with(['trialing', 'active']);

    it('puts the workspace back on Free when the subscription ends, deleting nothing', function (string $status) {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());
        stripeHolds($this->stripe, $this->tenant, ['status' => $status]);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect($outcome)->toBe('ended')
            ->and($this->tenant->fresh()->currentPlan()->key)->toBe('free')
            ->and($this->tenant->fresh()->subscription()->first()->status)->toBe('expired');
    })->with(['canceled', 'unpaid', 'incomplete_expired', 'paused']);

    it('changes nothing while the first payment is still being made', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'incomplete']);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect($outcome)->toBe('incomplete')
            ->and($this->tenant->fresh()->subscription()->first()?->source)->not->toBe('stripe')
            ->and($this->tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('records the first subscription and every applied message', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);

        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect(AuditLog::where('action', 'billing.card_subscribed')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
            ->and(AuditLog::where('action', 'billing.card_event')->where('tenant_id', $this->tenant->id)->count())->toBe(2);
    });

    it('applies a finished checkout at once', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'trialing', 'trialEnd' => CarbonImmutable::parse('2026-11-10 09:00:00')]);

        app(CardSubscriptions::class)->handle(cardEvent('checkout.session.completed', ['id' => 'cs_1', 'object' => 'checkout.session', 'mode' => 'subscription', 'customer' => 'cus_test_1', 'subscription' => 'sub_test_1']));

        expect($this->tenant->fresh()->currentPlan()->key)->toBe('pro');
    });

    it('makes a card saved in a setup checkout the one the subscription is charged to', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        $outcome = app(CardSubscriptions::class)->handle(cardEvent('checkout.session.completed', [
            'id' => 'cs_2', 'object' => 'checkout.session', 'mode' => 'setup', 'customer' => 'cus_test_1', 'setup_intent' => 'seti_1',
            'metadata' => ['tenant_id' => $this->tenant->id, 'purpose' => 'card_change'],
        ]));

        expect($outcome)->toBe('card_changed')
            ->and($this->stripe->calls('useSetupPaymentMethod'))->toBe([['seti_1', 'sub_test_1']]);
    });
});

describe('who a message is about', function () {
    it('ignores a customer it does not know', function () {
        $this->stripe->seedSubscription(FakeStripeGateway::subscriptionData(['customerId' => 'cus_someone_else']));

        $outcome = app(CardSubscriptions::class)->handle(cardEvent('customer.subscription.updated', ['id' => 'sub_test_1', 'customer' => 'cus_someone_else']));

        expect($outcome)->toBe('unknown')->and($this->tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('ignores a message from the other mode', function () {
        stripeHolds($this->stripe, $this->tenant);

        $outcome = app(CardSubscriptions::class)->handle(cardEvent('customer.subscription.updated', ['id' => 'sub_test_1', 'customer' => 'cus_test_1'], livemode: true));

        expect($outcome)->toBe('unknown')->and($this->tenant->fresh()->currentPlan()->key)->toBe('free');
    });

    it('ignores, and reports, a subscription whose metadata names another workspace', function () {
        Log::spy();
        $this->stripe->seedSubscription(FakeStripeGateway::subscriptionData(['metadata' => ['tenant_id' => 'another-workspace', 'plan' => 'pro', 'period' => 'year']]));

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect($outcome)->toBe('ignored')->and($this->tenant->fresh()->currentPlan()->key)->toBe('free');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'another workspace'))->once();
    });

    it('leaves the plan alone for an older subscription than the one the workspace follows', function () {
        stripeHolds($this->stripe, $this->tenant, ['id' => 'sub_new', 'status' => 'active', 'created' => CarbonImmutable::now()]);
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent('sub_new'));
        stripeHolds($this->stripe, $this->tenant, ['id' => 'sub_old', 'status' => 'past_due', 'created' => CarbonImmutable::now()->subMonth()]);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent('sub_old'));

        expect($outcome)->toBe('ignored')
            ->and($this->tenant->fresh()->subscription()->first()->stripe_subscription_id)->toBe('sub_new')
            ->and($this->stripe->calls('cancelNow'))->toBe([]);
    });

    it('ignores the end of a subscription the workspace no longer follows', function () {
        $this->tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addMonth(), ['source' => 'admin']);
        stripeHolds($this->stripe, $this->tenant, ['status' => 'canceled']);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent(type: 'customer.subscription.deleted'));

        expect($outcome)->toBe('ignored')
            ->and($this->tenant->fresh()->subscription()->first()->source)->toBe('admin')
            ->and(noticesOf($this->owner, 'billing.expired'))->toBe(0);
    });
});

describe('each message once', function () {
    it('applies a message sent again only once', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);
        $event = subscriptionChangedEvent(eventId: 'evt_same');

        expect(app(CardSubscriptions::class)->handle($event))->toBe('active')
            ->and(app(CardSubscriptions::class)->handle($event))->toBe('duplicate')
            ->and(StoreEvent::query()->where('provider', 'stripe')->where('event_id', 'evt_same')->sole()->outcome)->toBe('active')
            ->and(AuditLog::where('action', 'billing.card_event')->count())->toBe(1);
    });

    it('keeps only the type and the object\'s id of a message, never its details', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);

        app(CardSubscriptions::class)->handle(cardEvent('customer.subscription.updated', ['id' => 'sub_test_1', 'customer' => 'cus_test_1', 'customer_email' => 'owner@example.test'], 'evt_kept'));

        expect(StoreEvent::query()->where('event_id', 'evt_kept')->sole()->payload)->toBe(['type' => 'customer.subscription.updated', 'object' => 'sub_test_1']);
    });

    it('treats the same message arriving twice at the same moment as a duplicate', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);
        // The other request stores the message between this one's look and its own insert.
        StoreEvent::creating(function () {
            DB::table('store_events')->insertOrIgnore(['id' => (string) str()->uuid(), 'provider' => 'stripe', 'event_id' => 'evt_race', 'payload' => '{}', 'created_at' => now()]);
        });

        expect(app(CardSubscriptions::class)->handle(subscriptionChangedEvent(eventId: 'evt_race')))->toBe('duplicate');
    });

    it('applies again a message whose first try failed half way', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);
        (new StoreEvent)->forceFill(['provider' => 'stripe', 'event_id' => 'evt_retry', 'payload' => []])->save();

        expect(app(CardSubscriptions::class)->handle(subscriptionChangedEvent(eventId: 'evt_retry')))->toBe('active');
    });
});

describe('a second paid subscription', function () {
    it('is cancelled and refunded when the workspace already pays through Google Play', function () {
        $this->tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addMonth(), ['source' => 'play', 'auto_renew' => true]);
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent());

        expect($outcome)->toBe('refunded_duplicate')
            ->and($this->stripe->calls('cancelNow'))->toBe([['sub_test_1', true]])
            ->and($this->stripe->refunded('sub_test_1'))->toBeTrue()
            ->and($this->tenant->fresh()->subscription()->first()->source)->toBe('play')
            ->and(AuditLog::where('action', 'billing.card_refunded_duplicate')->where('tenant_id', $this->tenant->id)->count())->toBe(1)
            ->and(noticesOf($this->owner, 'billing.refunded'))->toBe(1);
    });

    it('is cancelled and refunded when it is newer than the card subscription the workspace follows', function () {
        stripeHolds($this->stripe, $this->tenant, ['id' => 'sub_first', 'status' => 'active', 'created' => CarbonImmutable::now()->subDays(10)]);
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent('sub_first'));
        stripeHolds($this->stripe, $this->tenant, ['id' => 'sub_second', 'status' => 'active', 'created' => CarbonImmutable::now()]);

        $outcome = app(CardSubscriptions::class)->handle(subscriptionChangedEvent('sub_second'));

        expect($outcome)->toBe('refunded_duplicate')
            ->and($this->stripe->calls('cancelNow'))->toBe([['sub_second', true]])
            ->and($this->tenant->fresh()->subscription()->first()->stripe_subscription_id)->toBe('sub_first');
    });

    it('is fine once the other plan has ended', function () {
        $this->tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'expired', now()->subMonths(2), ['source' => 'play']);
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active']);

        expect(app(CardSubscriptions::class)->handle(subscriptionChangedEvent()))->toBe('active')
            ->and($this->stripe->calls('cancelNow'))->toBe([]);
    });
});

describe('telling the owner', function () {
    beforeEach(function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'trialing', 'trialEnd' => CarbonImmutable::parse('2026-11-10 09:00:00'), 'periodEnd' => CarbonImmutable::parse('2026-11-10 09:00:00')]);
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent());
    });

    it('says when the trial ends and what the card will be charged', function () {
        app(CardSubscriptions::class)->handle(subscriptionChangedEvent(type: 'customer.subscription.trial_will_end'));

        $notice = AppNotification::query()->withoutGlobalScopes()->where('type', 'billing.trial_ending')->sole();
        expect($notice->user_id)->toBe($this->owner->id)
            ->and($notice->body)->toContain('10 November 2026')
            ->and($notice->body)->toContain('120.00');
    });

    it('says a renewal went through, but not for the first payment', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'active', 'periodEnd' => CarbonImmutable::parse('2027-11-10 09:00:00')]);
        $invoice = fn (string $reason, string $id) => cardEvent('invoice.paid', [
            'id' => $id, 'object' => 'invoice', 'customer' => 'cus_test_1', 'billing_reason' => $reason,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_test_1']],
        ]);

        app(CardSubscriptions::class)->handle($invoice('subscription_create', 'in_1'));
        expect(noticesOf($this->owner, 'billing.renewed'))->toBe(0);

        app(CardSubscriptions::class)->handle($invoice('subscription_cycle', 'in_2'));
        expect(noticesOf($this->owner, 'billing.renewed'))->toBe(1)
            ->and($this->tenant->fresh()->subscription()->first()->current_period_end->toDateString())->toBe('2027-11-10');
    });

    it('says a payment failed, once however many times Stripe retries', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'past_due']);
        $failed = fn (string $id) => cardEvent('invoice.payment_failed', [
            'id' => $id, 'object' => 'invoice', 'customer' => 'cus_test_1', 'billing_reason' => 'subscription_cycle',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_test_1']],
        ]);

        app(CardSubscriptions::class)->handle($failed('in_3'));
        app(CardSubscriptions::class)->handle($failed('in_3_retry'));

        expect(noticesOf($this->owner, 'billing.payment_failed'))->toBe(1)
            ->and(AppNotification::query()->withoutGlobalScopes()->where('type', 'billing.payment_failed')->value('body'))->not->toContain('Google Play');
    });

    it('asks the owner to confirm a payment the bank holds', function () {
        app(CardSubscriptions::class)->handle(cardEvent('invoice.payment_action_required', [
            'id' => 'in_4', 'object' => 'invoice', 'customer' => 'cus_test_1',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_test_1']],
        ]));

        expect(noticesOf($this->owner, 'billing.action_needed'))->toBe(1);
    });

    it('says the plan has ended', function () {
        stripeHolds($this->stripe, $this->tenant, ['status' => 'canceled']);

        app(CardSubscriptions::class)->handle(subscriptionChangedEvent(type: 'customer.subscription.deleted'));

        expect(noticesOf($this->owner, 'billing.expired'))->toBe(1);
    });
});
