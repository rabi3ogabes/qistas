<?php

use App\Billing\BillingSettings;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ManualPaymentReceipt;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
| Win Plan PP13: Pro by bank transfer. On the website the owner sends the transfer's reference and receipt for a plan and
| a period, priced from the plan; it waits in the Qistas team's queue. A super admin approves it (the workspace is on the
| plan for the period paid, added to any time left) or rejects it with a reason, and the owner is told either way. When
| automatic approval is switched on, the plan turns on as soon as the receipt arrives.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
    switchOn(Feature::ManualBilling);
    app(BillingSettings::class)->set(BillingSettings::BANK_DETAILS, "Al Rajhi Bank\nQistas Ltd\nIBAN SA03 8000 0000 6080 1016 7519");
});

/** @return array{0: User, 1: Tenant} */
function transferOwner(): array
{
    [$owner, $tenant] = owner(['name' => 'Al-Fares Electronics']);
    $tenant->forceFill(['owner_user_id' => $owner->id])->save();

    return [$owner, $tenant];
}

/** @param  array<string, mixed>  $overrides */
function reportTransfer(User $owner, array $overrides = []): TestResponse
{
    return test()->actingAs($owner)->post(route('app.billing.manual.store'), $overrides + [
        'plan' => 'pro', 'period' => 'month', 'method' => 'bank_transfer', 'reference' => 'TRX-4471',
        'receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800), 'idempotency_key' => 'web-transfer-1',
    ]);
}

function billingStaff(string $role = 'super_admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

function pendingRequest(Tenant $tenant): ManualPaymentRequest
{
    return ManualPaymentRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->firstOrFail();
}

describe('the owner reports a transfer', function () {
    it('sends the reference and receipt for a plan and period, priced from the plan, and waits for the team', function () {
        [$owner, $tenant] = transferOwner();

        $this->actingAs($owner)->get(route('app.billing'))->assertOk()
            ->assertSee('Pay by bank transfer')->assertSee('Al Rajhi Bank')->assertSee('IBAN SA03 8000 0000 6080 1016 7519');
        reportTransfer($owner, ['period' => 'year'])->assertRedirect(route('app.billing'))->assertSessionHas('status');

        $request = pendingRequest($tenant);
        expect($request->status)->toBe('pending')->and($request->amount)->toBe('120.0000')->and($request->currency)->toBe('USD')
            ->and($request->period)->toBe('year')->and($request->reference)->toBe('TRX-4471')->and($request->created_by_user_id)->toBe($owner->id)
            ->and(ManualPaymentReceipt::query()->where('manual_payment_request_id', $request->id)->sole()->mime)->toBe('image/jpeg')
            ->and($tenant->fresh()->currentPlan()->key)->toBe('free');
        $this->actingAs($owner)->get(route('app.billing'))->assertSee('Waiting for the Qistas team')->assertSee('TRX-4471');
    });

    it('is safe to send twice, and keeps one request waiting at a time', function () {
        [$owner, $tenant] = transferOwner();

        reportTransfer($owner)->assertRedirect();
        // The same key again is the same request: thanked, not refused.
        reportTransfer($owner)->assertRedirect()->assertSessionHasNoErrors();
        expect(ManualPaymentRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);

        reportTransfer($owner, ['idempotency_key' => 'web-transfer-2'])->assertSessionHasErrors('request');
        expect(ManualPaymentRequest::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('takes a picture or a PDF as the receipt, and only a plan with a price', function () {
        [$owner, $tenant] = transferOwner();

        reportTransfer($owner, ['receipt' => UploadedFile::fake()->createWithContent('receipt.jpg', 'just some text')])
            ->assertSessionHasErrors(['receipt' => 'Send the receipt as a picture (JPG or PNG) or a PDF.']);
        reportTransfer($owner, ['plan' => 'free'])->assertSessionHasErrors('plan');
        // A paid plan the admin has not priced yet cannot be paid for.
        Plan::query()->forceCreate(['name' => 'Studio', 'key' => 'studio', 'is_default' => false, 'is_public' => true, 'sort_order' => 30]);
        reportTransfer($owner, ['plan' => 'studio'])->assertSessionHasErrors('plan');
        reportTransfer($owner, ['period' => 'week'])->assertSessionHasErrors('period');

        expect(ManualPaymentRequest::withoutGlobalScopes()->count())->toBe(0);
    });

    it('accepts a PDF receipt as it is', function () {
        [$owner, $tenant] = transferOwner();

        reportTransfer($owner, ['receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")])->assertRedirect();

        expect(ManualPaymentReceipt::query()->sole()->mime)->toBe('application/pdf');
    });

    it('is for the owner of a real workspace, while the platform has it switched on', function () {
        [, $tenant] = transferOwner();
        $manager = memberAs('manager', $tenant);

        $this->actingAs($manager)->get(route('app.billing'))->assertOk()->assertDontSee('Pay by bank transfer');
        reportTransfer($manager)->assertForbidden();

        [$demoOwner] = owner(['is_demo' => true]);
        reportTransfer($demoOwner)->assertForbidden();

        [$owner] = transferOwner();
        app(FeatureControl::class)->setState(Feature::ManualBilling, PlatformState::Off, null, null);
        $this->actingAs($owner)->get(route('app.billing'))->assertOk()->assertDontSee('Pay by bank transfer');
        // A browser is sent back with the reason, as for any feature that is switched off.
        reportTransfer($owner)->assertRedirect()->assertSessionHas('error');

        expect(ManualPaymentRequest::withoutGlobalScopes()->count())->toBe(0);
    });

    it('turns the plan on at once when automatic approval is switched on', function () {
        app(BillingSettings::class)->set(BillingSettings::AUTO_ACTIVATE, '1');
        [$owner, $tenant] = transferOwner();

        reportTransfer($owner, ['period' => 'year'])->assertRedirect();

        $request = pendingRequest($tenant);
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->sole();
        expect($request->status)->toBe('approved')->and($request->decided_by_user_id)->toBeNull()
            ->and($tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and($subscription->current_period_end->toDateString())->toBe('2027-10-11')
            ->and($subscription->source)->toBe('manual');
    });

    it('waits for the team while automatic approval is off', function () {
        app(BillingSettings::class)->set(BillingSettings::AUTO_ACTIVATE, '0');
        [$owner, $tenant] = transferOwner();

        reportTransfer($owner)->assertRedirect();

        expect(pendingRequest($tenant)->status)->toBe('pending')->and($tenant->fresh()->currentPlan()->key)->toBe('free');
    });
});

describe('the team decides', function () {
    it('approving puts the workspace on the plan for the period paid, audits it and tells the owner', function () {
        [$owner, $tenant] = transferOwner();
        reportTransfer($owner)->assertRedirect();
        $admin = billingStaff();

        $this->actingAs($admin)->post(route('admin.billing.approve', pendingRequest($tenant)))->assertRedirect()->assertSessionHas('status');

        $request = pendingRequest($tenant);
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->sole();
        expect($request->status)->toBe('approved')->and($request->decided_by_user_id)->toBe($admin->id)
            ->and($tenant->fresh()->currentPlan()->key)->toBe('pro')
            ->and($subscription->current_period_end->toDateString())->toBe('2026-11-11')->and($subscription->source)->toBe('manual')
            ->and(AuditLog::query()->where('action', 'billing.manual_approved')->where('tenant_id', $tenant->id)->where('user_id', $admin->id)->count())->toBe(1)
            // The business's plan history in the admin area shows it too.
            ->and(AuditLog::query()->where('action', 'admin.plan_changed')->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(AppNotification::withoutGlobalScopes()->where('user_id', $owner->id)->where('type', 'billing.approved')->count())->toBe(1);
    });

    it('adds a renewal paid before the end to the time left', function () {
        [$owner, $tenant] = transferOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->addDays(9)->endOfDay(), ['source' => 'manual']);
        reportTransfer($owner)->assertRedirect();

        $this->actingAs(billingStaff())->post(route('admin.billing.approve', pendingRequest($tenant)))->assertRedirect();

        expect(Subscription::query()->where('tenant_id', $tenant->id)->sole()->current_period_end->toDateString())->toBe('2026-11-20');
    });

    it('rejecting needs a reason, changes no plan, and tells the owner why', function () {
        [$owner, $tenant] = transferOwner();
        reportTransfer($owner)->assertRedirect();
        $admin = billingStaff();

        $this->actingAs($admin)->post(route('admin.billing.reject', pendingRequest($tenant)), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($admin)->post(route('admin.billing.reject', pendingRequest($tenant)), ['note' => 'No transfer with this reference reached the account.'])->assertRedirect();

        expect(pendingRequest($tenant)->status)->toBe('rejected')->and($tenant->fresh()->currentPlan()->key)->toBe('free')
            ->and(AppNotification::withoutGlobalScopes()->where('user_id', $owner->id)->where('type', 'billing.rejected')->value('body'))->toContain('No transfer with this reference');
        $this->actingAs($owner)->get(route('app.billing'))->assertSee('Not confirmed')->assertSee('No transfer with this reference reached the account.');
    });

    it('decides a request once', function () {
        [$owner, $tenant] = transferOwner();
        reportTransfer($owner)->assertRedirect();
        $admin = billingStaff();

        $this->actingAs($admin)->post(route('admin.billing.approve', pendingRequest($tenant)))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.billing.reject', pendingRequest($tenant)), ['note' => 'Changed my mind'])->assertSessionHasErrors('request');
        $this->actingAs($admin)->post(route('admin.billing.approve', pendingRequest($tenant)))->assertSessionHasErrors('request');

        expect(pendingRequest($tenant)->status)->toBe('approved')
            ->and(Subscription::query()->where('tenant_id', $tenant->id)->sole()->current_period_end->toDateString())->toBe('2026-11-11');
    });

    it('lists what waits first, lets other staff look, and lets only a super admin decide', function () {
        [$owner, $tenant] = transferOwner();
        reportTransfer($owner)->assertRedirect();
        $staff = billingStaff('admin');

        $this->actingAs($staff)->get(route('admin.billing.index'))->assertOk()->assertSee('Al-Fares Electronics')->assertSee('TRX-4471');
        $this->actingAs($staff)->post(route('admin.billing.approve', pendingRequest($tenant)))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.billing.index'))->assertNotFound();

        expect(pendingRequest($tenant)->status)->toBe('pending');
    });

    it('opens the receipt for the team, and for nobody else', function () {
        [$owner, $tenant] = transferOwner();
        reportTransfer($owner)->assertRedirect();

        $this->actingAs(billingStaff('admin'))->get(route('admin.billing.receipt', pendingRequest($tenant)))->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($owner)->get(route('admin.billing.receipt', pendingRequest($tenant)))->assertNotFound();
    });
});

describe('the billing settings', function () {
    it('saves the bank details, the automatic switch and the Google Play keys, and never shows the key again', function () {
        $admin = billingStaff();
        $account = ['type' => 'service_account', 'client_email' => 'play@qistas.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nSECRET\n-----END PRIVATE KEY-----\n"];

        $this->actingAs($admin)->put(route('admin.billing.settings'), [
            'auto_activate' => '1', 'bank_details' => "SNB\nIBAN SA44 2000 0001 2345 6789 1234",
            'play_package' => 'app.qistas', 'play_account' => json_encode($account), 'play_products' => ['pro' => 'qistas_pro'],
            'push_audience' => 'https://qistas.app/webhooks/google-play', 'push_email' => 'pubsub@qistas.iam.gserviceaccount.com',
        ])->assertRedirect()->assertSessionHas('status');

        $settings = app(BillingSettings::class);
        expect($settings->autoActivate())->toBeTrue()->and($settings->bankDetails())->toContain('SA44')
            ->and($settings->playPackage())->toBe('app.qistas')->and($settings->playAccount()['client_email'])->toBe('play@qistas.iam.gserviceaccount.com')
            ->and($settings->playProducts())->toBe(['pro' => 'qistas_pro'])->and($settings->pushEmail())->toBe('pubsub@qistas.iam.gserviceaccount.com');
        $this->actingAs($admin)->get(route('admin.billing.index'))->assertOk()
            ->assertSee('play@qistas.iam.gserviceaccount.com')->assertDontSee('SECRET');

        // Saving again without a new key keeps the one saved.
        $this->actingAs($admin)->put(route('admin.billing.settings'), ['auto_activate' => '0', 'bank_details' => 'SNB', 'play_package' => 'app.qistas', 'play_account' => '', 'play_products' => ['pro' => 'qistas_pro']])->assertRedirect();
        expect(app(BillingSettings::class)->playAccount())->not->toBeNull()->and(app(BillingSettings::class)->autoActivate())->toBeFalse();
    });

    it('refuses a service account that is not one, and is for a super admin only', function () {
        $this->actingAs(billingStaff())->put(route('admin.billing.settings'), ['play_account' => '{"hello": "world"}'])->assertSessionHasErrors('play_account');
        $this->actingAs(billingStaff('admin'))->put(route('admin.billing.settings'), ['auto_activate' => '1'])->assertForbidden();

        expect(app(BillingSettings::class)->autoActivate())->toBeFalse();
    });
});
