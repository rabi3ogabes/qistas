<?php

use App\Billing\BillingSettings;
use App\Billing\BillingState;
use App\Billing\Card\CardCheckout;
use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\Data\SubscriptionCheckout;
use App\Billing\Card\FakeStripeGateway;
use App\Billing\Card\StripeGateway;
use App\Billing\ManualPayments;
use App\Billing\Store\FakeStoreBilling;
use App\Billing\Store\StoreBilling;
use App\Billing\Store\StorePurchase;
use App\Billing\StoreSubscriptions;
use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
| Starting a card checkout (spec 2026-10-27 §4.1, §5, §9): only the owner of a real workspace, only while the platform
| has card payments on and set up, never while the workspace already pays for a plan another way. A checkout already
| open is reused, so two tabs never make two payments; the trial is offered once per workspace.
*/

beforeEach(function () {
    $this->travelTo('2026-10-27 09:00:00');
    switchOn(Feature::CardBilling);
    $settings = app(CardSettings::class);
    $settings->saveKeys('sk_test_51Checkout', 'pk_test_51Checkout', User::factory()->create(['platform_role' => 'super_admin']));
    $settings->saveWebhook('we_1', 'whsec_checkout');
    $this->stripe = new FakeStripeGateway;
    app()->instance(StripeGateway::class, $this->stripe);
});

/** @return array{0: User, 1: Tenant} */
function checkoutOwner(array $tenant = []): array
{
    [$owner, $workspace] = owner($tenant);
    $workspace->forceFill(['owner_user_id' => $owner->id])->save();

    return [$owner, $workspace];
}

function startCheckout(User $as, array $body = []): TestResponse
{
    return test()->actingAs($as)->postJson(route('app.billing.card.session'), $body + ['plan' => 'pro', 'period' => 'year', 'attempt' => 'attempt-0000000000000001']);
}

function lastCheckout(FakeStripeGateway $stripe): SubscriptionCheckout
{
    $calls = $stripe->calls('createSubscriptionCheckout');

    return end($calls)[0];
}

describe('the owner', function () {
    it('gets a checkout to pay in, with the publishable key and nothing secret', function () {
        [$owner] = checkoutOwner();

        $response = startCheckout($owner)->assertOk();

        expect($response->json('client_secret'))->toEndWith('_secret_fake')
            ->and($response->json('publishable_key'))->toBe('pk_test_51Checkout')
            ->and($response->getContent())->not->toContain('sk_test_51Checkout')
            ->and($response->getContent())->not->toContain('whsec_checkout');
    });

    it('opens it for the plan\'s Stripe price, with the workspace in the metadata and a way back', function () {
        [$owner, $tenant] = checkoutOwner();

        startCheckout($owner)->assertOk();

        $checkout = lastCheckout($this->stripe);
        expect($this->stripe->price($checkout->priceId)['lookup_key'])->toBe('qistas_pro_year_usd_12000')
            ->and($checkout->customerId)->toBe($tenant->fresh()->stripe_customer_id)
            ->and($checkout->metadata)->toBe(['tenant_id' => $tenant->id, 'plan' => 'pro', 'period' => 'year'])
            ->and($checkout->returnUrl)->toBe(route('app.billing.card.return').'?session_id={CHECKOUT_SESSION_ID}');
    });

    it('offers the trial while the workspace has never had one', function () {
        [$owner, $tenant] = checkoutOwner();
        startCheckout($owner)->assertOk();
        expect(lastCheckout($this->stripe)->trialDays)->toBe(14);

        [$secondOwner, $second] = checkoutOwner();
        $second->forceFill(['trial_used_at' => now()->subYear()])->save();
        startCheckout($secondOwner)->assertOk();
        expect(lastCheckout($this->stripe)->trialDays)->toBeNull();
    });

    it('offers no trial when the admin set it to 0 days', function () {
        app(CardSettings::class)->saveOptions(['trial_days' => 0, 'promotion_codes' => false, 'collect_tax_id' => false, 'automatic_tax' => false], User::factory()->create(['platform_role' => 'super_admin']));
        [$owner] = checkoutOwner();

        startCheckout($owner)->assertOk();

        expect(lastCheckout($this->stripe)->trialDays)->toBeNull();
    });

    it('passes the admin\'s promotion code and tax options', function () {
        app(CardSettings::class)->saveOptions(['trial_days' => 14, 'promotion_codes' => true, 'collect_tax_id' => true, 'automatic_tax' => false], User::factory()->create(['platform_role' => 'super_admin']));
        [$owner] = checkoutOwner();

        startCheckout($owner)->assertOk();

        $checkout = lastCheckout($this->stripe);
        expect($checkout->promotionCodes)->toBeTrue()->and($checkout->collectTaxId)->toBeTrue()->and($checkout->automaticTax)->toBeFalse();
    });

    it('speaks the owner\'s language in Stripe\'s fields, or lets Stripe choose for Urdu', function (string $language, string $stripeLocale) {
        [$owner] = checkoutOwner();
        $owner->forceFill(['locale' => $language])->save();

        startCheckout($owner)->assertOk();

        expect(lastCheckout($this->stripe)->locale)->toBe($stripeLocale);
    })->with([['ar', 'ar'], ['fr', 'fr'], ['es', 'es'], ['en', 'en'], ['ur', 'auto']]);

    it('reuses the checkout already open instead of making a second one', function () {
        [$owner] = checkoutOwner();

        $first = startCheckout($owner)->json('client_secret');
        $second = startCheckout($owner, ['attempt' => 'attempt-0000000000000002'])->json('client_secret');

        expect($second)->toBe($first)
            ->and($this->stripe->calls('createSubscriptionCheckout'))->toHaveCount(1);
    });

    it('opens a new checkout when the old one expired', function () {
        [$owner] = checkoutOwner();
        $first = startCheckout($owner)->json('client_secret');
        $this->stripe->expireSession(str_replace('_secret_fake', '', $first));

        $second = startCheckout($owner, ['attempt' => 'attempt-0000000000000002'])->json('client_secret');

        expect($second)->not->toBe($first)->and($this->stripe->calls('createSubscriptionCheckout'))->toHaveCount(2);
    });

    it('opens a separate checkout for the other period', function () {
        [$owner] = checkoutOwner();

        startCheckout($owner)->assertOk();
        startCheckout($owner, ['period' => 'month'])->assertOk();

        expect($this->stripe->calls('createSubscriptionCheckout'))->toHaveCount(2);
    });

    it('asks Stripe with a key made from the workspace, the offer and the attempt', function () {
        [$owner, $tenant] = checkoutOwner();

        startCheckout($owner)->assertOk();

        expect($this->stripe->calls('createSubscriptionCheckout')[0][1])->toBe(hash('sha256', $tenant->id.'|pro|year|attempt-0000000000000001'));
    });

    it('records the start with the plan and period only', function () {
        [$owner, $tenant] = checkoutOwner();

        startCheckout($owner)->assertOk();

        $entry = AuditLog::query()->where('action', 'billing.card_checkout_started')->sole();
        expect($entry->tenant_id)->toBe($tenant->id)->and($entry->user_id)->toBe($owner->id)
            ->and($entry->changes)->toBe(['plan' => 'pro', 'period' => 'year']);
    });

    it('shows the checkout page with what the script needs', function () {
        [$owner] = checkoutOwner();

        $this->actingAs($owner)->get(route('app.billing.checkout', ['plan' => 'pro', 'period' => 'month']))
            ->assertOk()
            ->assertSee('data-card-checkout', false)
            ->assertSee('data-session-url="'.route('app.billing.card.session').'"', false)
            ->assertSee('data-plan="pro"', false)
            ->assertSee('data-period="month"', false)
            ->assertSee('data-attempt="', false)
            ->assertDontSee('sk_test_51Checkout');
    });
});

describe('who cannot', function () {
    it('refuses everyone but the owner', function (string $role) {
        [, $tenant] = checkoutOwner();
        $member = memberAs($role, $tenant);
        $member->forceFill(['current_tenant_id' => $tenant->id])->save();

        startCheckout($member)->assertForbidden();
        $this->actingAs($member)->get(route('app.billing.checkout'))->assertForbidden();
        expect($this->stripe->calls('createSubscriptionCheckout'))->toBe([]);
    })->with(['manager', 'accountant', 'collector']);

    it('is refused by the checkout itself, whoever calls it, and the billing page offers it to the owner only', function () {
        [$owner, $tenant] = checkoutOwner();
        $manager = memberAs('manager', $tenant);

        expect(fn () => app(CardCheckout::class)->start($tenant, $manager, 'pro', 'year', 'attempt-1'))
            ->toThrow(CardRefused::class, 'Only the owner of the workspace can change its plan.');
        expect(app(BillingState::class)->cardOpen($tenant, $manager))->toBeFalse()
            ->and(app(BillingState::class)->cardOpen($tenant, $owner))->toBeTrue()
            ->and($this->stripe->calls('createSubscriptionCheckout'))->toBe([]);
    });

    it('refuses demo and test workspaces', function (string $flag) {
        [$owner] = checkoutOwner([$flag => true]);

        startCheckout($owner)->assertForbidden();
    })->with(['is_demo', 'is_test']);
});

describe('when card payments are not on', function () {
    it('hides the page and refuses the checkout while the switch is off', function () {
        switchOff(Feature::CardBilling);
        [$owner] = checkoutOwner();

        $this->actingAs($owner)->get(route('app.billing.checkout'))->assertNotFound();
        startCheckout($owner)->assertUnprocessable()->assertJsonPath('message', 'Card payments are switched off.');
    });

    it('refuses the checkout until Stripe is set up', function () {
        app(CardSettings::class)->clear(User::factory()->create(['platform_role' => 'super_admin']));
        [$owner] = checkoutOwner();

        startCheckout($owner)->assertUnprocessable()->assertJsonPath('message', 'Card payments are not set up yet.');
    });

    it('says Stripe is briefly unavailable, and that nothing was charged', function () {
        [$owner] = checkoutOwner();
        $this->stripe->failNext(new CardUnavailable('down'));

        startCheckout($owner)->assertStatus(503)
            ->assertJsonPath('message', 'Card payments are briefly unavailable. Nothing was charged; try again in a minute.');
    });

    it('refuses an unknown plan or period', function (array $body) {
        [$owner] = checkoutOwner();

        startCheckout($owner, $body)->assertUnprocessable();
    })->with([
        'unknown plan' => [['plan' => 'gold']],
        'the free plan' => [['plan' => 'free']],
        'unknown period' => [['period' => 'week']],
        'no attempt' => [['attempt' => '']],
    ]);
});

describe('a workspace that already pays', function () {
    it('cannot start a card checkout while paying another way', function (string $source) {
        [$owner, $tenant] = checkoutOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addMonth(), ['source' => $source]);

        startCheckout($owner)->assertUnprocessable()->assertJsonPath('message', 'Pro is already on for this workspace.');
        $this->actingAs($owner)->get(route('app.billing.checkout'))->assertRedirect(route('app.billing'));
    })->with(['play', 'manual', 'stripe']);

    it('can start one while on a plan the team gave', function () {
        [$owner, $tenant] = checkoutOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addMonth(), ['source' => 'admin']);

        startCheckout($owner)->assertOk();
    });

    it('can start one again once the paid plan has ended', function () {
        [$owner, $tenant] = checkoutOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'expired', now()->subMonths(2), ['source' => 'stripe']);

        startCheckout($owner)->assertOk();
    });
});

describe('the other ways to pay, while a card subscription runs', function () {
    beforeEach(function () {
        [$this->owner, $this->tenant] = checkoutOwner();
        $this->tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addYear(), ['source' => 'stripe', 'stripe_subscription_id' => 'sub_1', 'period' => 'year', 'auto_renew' => true]);
    });

    it('refuses a Google Play purchase', function () {
        switchOn(Feature::StoreBilling);
        $settings = app(BillingSettings::class);
        $settings->set(BillingSettings::PLAY_PACKAGE, 'app.qistas');
        $settings->set(BillingSettings::PLAY_PRODUCTS, (string) json_encode(['pro' => 'qistas_pro']));
        $store = new FakeStoreBilling;
        app()->instance(StoreBilling::class, $store);
        $store->record('play-token-9', new StorePurchase(
            productId: 'qistas_pro', state: 'active', expiresAt: CarbonImmutable::parse('2026-11-27 09:00:00'), autoRenew: true,
            acknowledged: true, accountRef: StoreSubscriptions::accountRef($this->tenant),
        ));
        Sanctum::actingAs($this->owner, ['app']);

        $this->postJson('/api/v1/billing/play/verify', ['product_id' => 'qistas_pro', 'purchase_token' => 'play-token-9'])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_subscribed');
        expect($this->tenant->fresh()->subscription()->first()->source)->toBe('stripe');
    });

    it('refuses a bank transfer', function () {
        switchOn(Feature::ManualBilling);
        app(BillingSettings::class)->set(BillingSettings::BANK_DETAILS, 'Qistas Ltd');

        expect(fn () => app(ManualPayments::class)->submit($this->tenant, 'pro', 'year', 'bank_transfer', 'TRX-1', UploadedFile::fake()->image('r.jpg', 300, 400), $this->owner, 'transfer-key-1'))
            ->toThrow(ValidationException::class);
    });

    it('does not offer the store in the app', function () {
        switchOn(Feature::StoreBilling);
        $settings = app(BillingSettings::class);
        $settings->set(BillingSettings::PLAY_PACKAGE, 'app.qistas');
        $settings->set(BillingSettings::PLAY_ACCOUNT, (string) json_encode(['client_email' => 'play@x.iam.gserviceaccount.com', 'private_key' => 'unused']));
        $settings->set(BillingSettings::PLAY_PRODUCTS, (string) json_encode(['pro' => 'qistas_pro']));

        expect(app(BillingState::class)->for($this->tenant, $this->owner)['store']['available'])->toBeFalse();
    });

    it('does not offer card checkout either', function () {
        expect(app(BillingState::class)->cardOpen($this->tenant, $this->owner))->toBeFalse();
    });
});

it('offers card checkout to the owner of a workspace that pays nothing', function () {
    [$owner, $tenant] = checkoutOwner();

    expect(app(BillingState::class)->cardOpen($tenant, $owner))->toBeTrue();
});
