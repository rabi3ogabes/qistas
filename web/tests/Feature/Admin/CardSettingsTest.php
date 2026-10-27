<?php

use App\Billing\Card\CardSettings;
use App\Billing\Card\CardSubscriptions;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Stripe\Exception\AuthenticationException;

/*
| Card payments (Stripe) in /admin/billing (spec 2026-10-27 §8): a super admin pastes the two keys and Qistas connects
| itself — the account checked, the webhook made with exactly the events it follows, its secret kept, the site's domain
| registered for Apple Pay and Google Pay. Reconnect replaces the endpoint, Disconnect removes it and forgets the keys.
| Then the trial and the checkout's switches, and a check of every paid price against what Stripe can charge. Keys are
| never shown back whole. Other staff may look; only a super admin changes anything.
*/

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    config(['app.url' => 'https://qistas.app']);
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
});

function stripePanelStaff(string $role = 'super_admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function saveStripeKeys(array $keys = [], ?User $who = null): TestResponse
{
    return test()->actingAs($who ?? stripePanelStaff())->put(route('admin.billing.card.keys'), $keys + [
        'secret_key' => 'sk_test_51QistasSecretAb12',
        'publishable_key' => 'pk_test_51QistasPublishCd34',
    ]);
}

describe('the panel', function () {
    it('is there for all platform staff, and only a super admin can change it', function () {
        $support = stripePanelStaff('admin');

        $this->actingAs($support)->get(route('admin.billing.index'))->assertOk()
            ->assertSee('Card payments (Stripe)')->assertSee('Not connected yet')
            ->assertDontSee('Save and connect');
        $this->actingAs(stripePanelStaff())->get(route('admin.billing.index'))->assertOk()->assertSee('Save and connect');

        saveStripeKeys(who: $support)->assertForbidden();
        $this->actingAs($support)->post(route('admin.billing.card.connect'))->assertForbidden();
        $this->actingAs($support)->post(route('admin.billing.card.disconnect'))->assertForbidden();
        $this->actingAs($support)->put(route('admin.billing.card.settings'), ['trial_days' => 7])->assertForbidden();
        expect(app(CardSettings::class)->secretKey())->toBeNull()
            ->and($this->stripe->calls('account'))->toBe([]);
    });

    it('says ready to take cards, with the mode and the account, only once it is', function () {
        $page = fn () => $this->actingAs(stripePanelStaff('admin'))->get(route('admin.billing.index'))->assertOk();
        $page()->assertDontSee('Ready to take cards');

        saveStripeKeys()->assertRedirect(route('admin.billing.index'));

        $page()->assertSee('Ready to take cards · Test mode')->assertSee('Qistas Ltd')->assertSee('acct_test_qistas');
    });

    it('is not for anyone outside the platform team', function () {
        [$owner] = owner();

        $this->actingAs($owner)->put(route('admin.billing.card.keys'), ['secret_key' => 'sk_test_1', 'publishable_key' => 'pk_test_1'])->assertNotFound();
    });
});

describe('saving the keys', function () {
    it('saves both keys and connects in one step: account, webhook with exactly the events followed, domain', function () {
        $admin = stripePanelStaff();

        saveStripeKeys(who: $admin)->assertRedirect(route('admin.billing.index'))->assertSessionHas('status', 'Stripe is connected. Card payments are ready in test mode.');

        $settings = app(CardSettings::class);
        [$url, $events] = $this->stripe->calls('createWebhook')[0];
        $endpoint = $settings->webhookEndpointId();
        expect($settings->secretKey())->toBe('sk_test_51QistasSecretAb12')
            ->and($settings->publishableKey())->toBe('pk_test_51QistasPublishCd34')
            ->and($url)->toBe('https://qistas.app/webhooks/stripe')
            ->and($events)->toBe(CardSubscriptions::EVENTS)
            ->and($endpoint)->not->toBeNull()
            ->and($settings->webhookSecret())->toBe($this->stripe->webhook($endpoint)['secret'])
            ->and($this->stripe->domains())->toBe(['qistas.app'])
            ->and($settings->ready())->toBeTrue();

        $audit = AuditLog::query()->where('action', 'admin.card_connected')->sole();
        expect($audit->changes)->toMatchArray(['mode' => 'test', 'account' => 'acct_test_qistas'])
            ->and($audit->user_id)->toBe($admin->id)
            ->and(json_encode($audit->changes))->not->toContain('sk_test_51QistasSecretAb12');
    });

    it('shows the secret key afterwards only masked, and never the keys themselves', function () {
        saveStripeKeys();

        $this->actingAs(stripePanelStaff())->get(route('admin.billing.index'))->assertOk()
            ->assertSee('sk_test_…Ab12')
            ->assertDontSee('sk_test_51QistasSecretAb12')
            ->assertDontSee('pk_test_51QistasPublishCd34');
    });

    it('refuses a pair that mixes test and live, or a key that is not a Stripe key, and saves nothing', function (array $keys, string $field) {
        saveStripeKeys($keys)->assertSessionHasErrors($field);

        expect(app(CardSettings::class)->secretKey())->toBeNull()
            ->and($this->stripe->calls('account'))->toBe([])
            ->and(session()->getOldInput())->not->toHaveKeys(['secret_key', 'publishable_key']);
    })->with([
        'test with live' => [['publishable_key' => 'pk_live_51QistasPublishCd34'], 'publishable_key'],
        'not a secret key' => [['secret_key' => 'pk_test_51QistasSecretAb12'], 'secret_key'],
        'empty' => [['secret_key' => ''], 'secret_key'],
    ]);

    it('shows Stripe’s own message for a key Stripe refuses, and saves nothing', function () {
        $this->stripe->failNext(new CardUnavailable('Card payments are briefly unavailable.', previous: new AuthenticationException('Invalid API Key provided: sk_test_****Ab12')));

        saveStripeKeys()->assertSessionHasErrors(['secret_key' => 'Stripe says: Invalid API Key provided: sk_test_****Ab12']);

        $settings = app(CardSettings::class);
        expect($settings->secretKey())->toBeNull()
            ->and($settings->webhookEndpointId())->toBeNull()
            ->and($this->stripe->calls('createWebhook'))->toBe([])
            ->and(AuditLog::query()->where('action', 'admin.card_connected')->exists())->toBeFalse();
    });

    it('stays connected for cards when Apple Pay and Google Pay cannot be set up for the domain', function () {
        $this->stripe->failNext(new CardUnavailable('Card payments are briefly unavailable.'), 'registerDomain');

        saveStripeKeys()->assertRedirect(route('admin.billing.index'))->assertSessionHasNoErrors();

        expect(app(CardSettings::class)->ready())->toBeTrue();
        $this->actingAs(stripePanelStaff())->get(route('admin.billing.index'))->assertOk()
            ->assertSee('Ready to take cards · Test mode')
            ->assertSee('Apple Pay and Google Pay are off: Stripe could not register qistas.app. Connect again to retry.');
    });
});

describe('connecting again and disconnecting', function () {
    it('replaces the webhook on Connect, deleting the old one', function () {
        saveStripeKeys();
        $first = app(CardSettings::class)->webhookEndpointId();

        $this->actingAs(stripePanelStaff())->post(route('admin.billing.card.connect'))->assertRedirect(route('admin.billing.index'))->assertSessionHas('status');

        $second = app(CardSettings::class)->webhookEndpointId();
        expect($second)->not->toBe($first)
            ->and($this->stripe->calls('deleteWebhook'))->toBe([[$first]])
            ->and($this->stripe->webhook($first))->toBeNull()
            ->and(app(CardSettings::class)->webhookSecret())->toBe($this->stripe->webhook($second)['secret']);
    });

    it('keeps the working endpoint when Connect fails', function () {
        saveStripeKeys();
        $settings = app(CardSettings::class);
        $endpoint = $settings->webhookEndpointId();
        $this->stripe->failNext(new CardUnavailable('Card payments are briefly unavailable.'), 'createWebhook');

        $this->actingAs(stripePanelStaff())->post(route('admin.billing.card.connect'))->assertSessionHasErrors('stripe');

        expect($settings->webhookEndpointId())->toBe($endpoint)
            ->and($this->stripe->webhook($endpoint))->not->toBeNull()
            ->and($this->stripe->calls('deleteWebhook'))->toBe([]);
    });

    it('cannot connect before the keys are saved', function () {
        $this->actingAs(stripePanelStaff())->post(route('admin.billing.card.connect'))->assertSessionHasErrors(['stripe' => 'Save the two keys first.']);

        expect($this->stripe->calls('account'))->toBe([]);
    });

    it('deletes the webhook and forgets the keys on Disconnect; the options stay for next time', function () {
        $admin = stripePanelStaff();
        saveStripeKeys(who: $admin);
        $this->actingAs($admin)->put(route('admin.billing.card.settings'), ['trial_days' => 30, 'promotion_codes' => '1'])->assertRedirect();
        $endpoint = app(CardSettings::class)->webhookEndpointId();

        $this->actingAs($admin)->post(route('admin.billing.card.disconnect'))->assertRedirect(route('admin.billing.index'))->assertSessionHas('status', 'Stripe is disconnected. Card payments are off until you connect again.');

        $settings = app(CardSettings::class);
        expect($this->stripe->calls('deleteWebhook'))->toBe([[$endpoint]])
            ->and($settings->secretKey())->toBeNull()
            ->and($settings->publishableKey())->toBeNull()
            ->and($settings->webhookSecret())->toBeNull()
            ->and($settings->webhookEndpointId())->toBeNull()
            ->and($settings->ready())->toBeFalse()
            ->and($settings->trialDays())->toBe(30)
            ->and($settings->promotionCodes())->toBeTrue()
            ->and(AuditLog::query()->where('action', 'admin.card_disconnected')->where('user_id', $admin->id)->exists())->toBeTrue();
        $this->actingAs($admin)->get(route('admin.billing.index'))->assertSee('Not connected yet')->assertDontSee('Qistas Ltd');
    });

    it('forgets the keys even when Stripe cannot be reached to delete the webhook', function () {
        saveStripeKeys();
        $this->stripe->failNext(new CardUnavailable('Card payments are briefly unavailable.'));

        $this->actingAs(stripePanelStaff())->post(route('admin.billing.card.disconnect'))->assertRedirect(route('admin.billing.index'));

        expect(app(CardSettings::class)->secretKey())->toBeNull()
            ->and(app(CardSettings::class)->webhookEndpointId())->toBeNull();
    });
});

describe('the options', function () {
    it('saves the free trial, promotion codes and the two tax switches', function () {
        $this->actingAs(stripePanelStaff())->put(route('admin.billing.card.settings'), [
            'trial_days' => 0, 'promotion_codes' => '1', 'collect_tax_id' => '1', 'automatic_tax' => '0',
        ])->assertRedirect(route('admin.billing.index'))->assertSessionHas('status', 'Card payment options saved.');

        $settings = app(CardSettings::class);
        expect($settings->trialDays())->toBe(0)
            ->and($settings->promotionCodes())->toBeTrue()
            ->and($settings->collectTaxId())->toBeTrue()
            ->and($settings->automaticTax())->toBeFalse();
    });

    it('refuses a free trial outside 0 to 90 days', function (mixed $days) {
        $this->actingAs(stripePanelStaff())->put(route('admin.billing.card.settings'), ['trial_days' => $days])->assertSessionHasErrors('trial_days');

        expect(app(CardSettings::class)->trialDays())->toBe(CardSettings::DEFAULT_TRIAL_DAYS);
    })->with([91, -1, 'two weeks', '']);
});

describe('the price check', function () {
    it('lists each paid price as ready, or why Stripe could not charge it', function () {
        Plan::where('key', 'pro')->sole()->forceFill(['currency' => 'KWD', 'price_monthly' => '4.5555', 'price_yearly' => '45.000'])->save();

        $this->actingAs(stripePanelStaff('admin'))->get(route('admin.billing.index'))->assertOk()
            ->assertSee('Price check')
            ->assertSee('4.5555 KWD')
            ->assertSee('KWD is charged with at most 3 decimals.')
            ->assertSee('Ready');
    });
});
