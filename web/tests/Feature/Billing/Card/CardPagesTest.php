<?php

use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\Data\CardInvoice;
use App\Billing\Card\Data\CardPaymentMethod;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Entitlements\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Support\Dates;
use App\Support\Format;
use Carbon\CarbonImmutable;

/*
| The checkout page and the card dashboard on the billing page (spec 2026-10-27 §4.1, §4.3): what an owner reads before
| paying, where a card subscription stands, and the actions that manage it. Stripe's own fields are mounted by script.
*/

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    switchOn(Feature::CardBilling);
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_test_51Pages', 'pk_test_51Pages', User::factory()->create(['platform_role' => 'super_admin']));
    $settings->saveWebhook('we_1', 'whsec_pages');
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
    [$this->owner, $this->tenant] = owner();
    $this->tenant->forceFill(['owner_user_id' => $this->owner->id, 'stripe_customer_id' => 'cus_test_1', 'stripe_livemode' => false])->save();
});

/** The workspace pays by card; Stripe holds the subscription with $overrides. */
function pagesPayingByCard(object $test, array $overrides = [], string $localStatus = 'active'): void
{
    $remote = FakeStripeGateway::subscriptionData($overrides + [
        'periodEnd' => CarbonImmutable::parse('2027-10-27 09:00:00'),
        'metadata' => ['tenant_id' => $test->tenant->id, 'plan' => 'pro', 'period' => 'year'],
    ]);
    $test->stripe->seedSubscription($remote);
    $test->tenant->subscribeTo(Plan::where('key', 'pro')->sole(), $localStatus, $remote->periodEnd, [
        'source' => 'stripe', 'stripe_subscription_id' => $remote->id, 'period' => $remote->interval, 'auto_renew' => ! $remote->cancelAtPeriodEnd,
    ]);
}

describe('the checkout page', function () {
    it('shows the plan, both periods with the yearly saving, and the price of the chosen one', function () {
        $page = $this->actingAs($this->owner)->get(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'year']))->assertOk();

        $page->assertSee('Pro')
            ->assertSee('href="'.e(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'month'])).'"', false)
            ->assertSee(__('Save :percent%', ['percent' => 17]))
            ->assertSee(Format::money('120.0000', 'USD'))
            ->assertSee('aria-current="page"', false);
    });

    it('shows the trial\'s timeline while the workspace has never had a trial', function () {
        $this->actingAs($this->owner)->get(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'year']))
            ->assertOk()
            ->assertSee(__('Free trial starts. Nothing to pay today.'))
            ->assertSee(Dates::day(now()->addDays(14), 'j F Y'))
            ->assertSee(__('First charge of :amount', ['amount' => Format::money('120.0000', 'USD')]))
            ->assertSee(__('Start my free trial'));
    });

    it('charges at once when the trial is used up', function () {
        $this->tenant->forceFill(['trial_used_at' => now()->subYear()])->save();

        $this->actingAs($this->owner)->get(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'month']))
            ->assertOk()
            ->assertDontSee(__('Free trial starts. Nothing to pay today.'))
            ->assertSee(__('Pay :amount', ['amount' => Format::money('12.0000', 'USD')]));
    });

    it('offers a promotion code only when the admin allows codes', function () {
        $this->actingAs($this->owner)->get(route('app.billing.checkout'))->assertOk()->assertDontSee('data-card-promo', false);

        app(CardSettings::class)->saveOptions(['trial_days' => 14, 'promotion_codes' => true, 'collect_tax_id' => false, 'automatic_tax' => false], User::factory()->create(['platform_role' => 'super_admin']));
        $this->actingAs($this->owner)->get(route('app.billing.checkout'))->assertOk()->assertSee('data-card-promo', false);
    });

    it('carries nothing secret and no inline script', function () {
        $html = $this->actingAs($this->owner)->get(route('app.billing.checkout'))->assertOk()->getContent();
        $body = substr($html, (int) strpos($html, '<body'));

        expect($html)->not->toContain('sk_test_51Pages')->not->toContain('whsec_pages')
            ->and($body)->not->toContain('<script');
    });

    it('tells the owner Stripe handles the card', function () {
        $this->actingAs($this->owner)->get(route('app.billing.checkout'))
            ->assertOk()->assertSee(__('Payments are handled by Stripe. Qistas never sees your card number.'));
    });
});

describe('the billing page for a card subscriber', function () {
    it('says until when the trial runs and what follows', function () {
        pagesPayingByCard($this, ['status' => 'trialing', 'trialEnd' => CarbonImmutable::parse('2026-11-10 09:00:00'), 'periodEnd' => CarbonImmutable::parse('2026-11-10 09:00:00')], 'trialing');

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee(__('Free trial until :date, then :amount a year.', ['date' => Dates::day(CarbonImmutable::parse('2026-11-10'), 'j F Y'), 'amount' => Format::money('120.0000', 'USD')]));
    });

    it('says when it renews and for how much, with the card and the ways to change it', function () {
        pagesPayingByCard($this);

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee(__('Renews on :date for :amount.', ['date' => Dates::day(CarbonImmutable::parse('2027-10-27'), 'j F Y'), 'amount' => Format::money('120.0000', 'USD')]))
            ->assertSee(__(':brand ending :last4', ['brand' => 'Visa', 'last4' => '4242']))
            ->assertSee(__('Expires :month/:year', ['month' => '12', 'year' => '2030']))
            ->assertSee('data-card-billing', false)
            ->assertSee(__('Change card'))
            ->assertSee(__('Switch to monthly'))
            ->assertSee('action="'.route('app.billing.card.cancel').'"', false);
    });

    it('offers to resume a subscription whose renewal is off', function () {
        pagesPayingByCard($this, ['cancelAtPeriodEnd' => true]);

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee(__('Ends on :date. Renewal is off.', ['date' => Dates::day(CarbonImmutable::parse('2027-10-27'), 'j F Y')]))
            ->assertSee('action="'.route('app.billing.card.resume').'"', false)
            ->assertDontSee('action="'.route('app.billing.card.cancel').'"', false);
    });

    it('says a monthly switch is coming at the end of the year', function () {
        pagesPayingByCard($this, ['scheduleId' => 'sub_sched_1', 'scheduledPriceId' => 'price_month', 'scheduledInterval' => 'month']);

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee(__('Monthly from :date.', ['date' => Dates::day(CarbonImmutable::parse('2027-10-27'), 'j F Y')]))
            ->assertSee(__('Keep yearly'));
    });

    it('asks for a new card after a failed payment', function () {
        pagesPayingByCard($this, ['status' => 'past_due'], 'past_due');

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()->assertSee(__('We could not take the last payment. Update your card; Pro stays on while Stripe tries again.'));
    });

    it('offers to confirm a payment the bank holds', function () {
        pagesPayingByCard($this, ['latestInvoiceId' => 'in_held']);
        $this->stripe->seedInvoice(new CardInvoice('in_held', 'QIS-0002', 'cus_test_1', CarbonImmutable::now(), '120.0000', 'usd', 'open', 'subscription_cycle', 'sub_test_1', 'pi_1', 'pi_1_secret', 'requires_action'));

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee(__('Your bank asks you to confirm the payment.'))
            ->assertSee('data-card-confirm', false);
    });

    it('lists the receipts with a download for each', function () {
        pagesPayingByCard($this);
        $this->stripe->seedInvoice(new CardInvoice('in_1', 'QIS-0001', 'cus_test_1', CarbonImmutable::parse('2026-10-27'), '120.0000', 'usd', 'paid', 'subscription_create', 'sub_test_1', 'pi_1', null, 'succeeded'));

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee('QIS-0001')
            ->assertSee('href="'.route('app.billing.card.receipt', ['invoice' => 'in_1']).'"', false);
    });

    it('names a wallet the card came from', function () {
        pagesPayingByCard($this);
        $this->stripe->setCard('sub_test_1', new CardPaymentMethod('mastercard', '5555', 3, 2031, 'apple_pay'));

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()->assertSee(__(':brand ending :last4', ['brand' => 'Mastercard', 'last4' => '5555']))->assertSee('Apple Pay');
    });

    it('still opens when Stripe cannot be reached', function () {
        pagesPayingByCard($this);
        $this->stripe->failNext(new CardUnavailable('down'));

        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()->assertSee(__('Card details are briefly unavailable. Try again in a minute.'));
    });

    it('shows the reason an action was refused', function () {
        pagesPayingByCard($this, ['cancelAtPeriodEnd' => true]);
        $this->stripe->failNext(new CardRefused('Your card was declined.'));

        $this->actingAs($this->owner)->followingRedirects()->post(route('app.billing.card.resume'))
            ->assertOk()->assertSee('Your card was declined.');
    });
});

describe('the billing page for a workspace on Free', function () {
    it('offers paying by card when the platform has it on', function () {
        $this->actingAs($this->owner)->get(route('app.billing'))
            ->assertOk()
            ->assertSee(__('Pay by card'))
            ->assertSee('href="'.e(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'year'])).'"', false)
            ->assertSee(__(':days-day free trial', ['days' => 14]));
    });

    it('does not offer it while the switch is off', function () {
        switchOff(Feature::CardBilling);

        $this->actingAs($this->owner)->get(route('app.billing'))->assertOk()->assertDontSee(__('Pay by card'));
    });
});
