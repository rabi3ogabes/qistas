<?php

use App\Billing\BillingNotices;
use App\Mail\MailSettings;
use App\Mail\NoticeMail;
use App\Models\AppNotification;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\Notifier;
use App\Notifications\Channels;
use App\Notifications\Push\FcmPushSender;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use App\Notifications\Push\PushSettings;
use App\Sms\SmsSender;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RecordingPushSender;

/*
| The notification centre (Sprint 3 Task 1b): every notice (instalment alerts, the morning summary, billing, support) can
| reach a person in the app (the inbox and a push), by e-mail and by SMS. The admin decides which kinds may use which
| channel for everyone; each person chooses, kind by kind and channel by channel, among what the admin allows and what is
| set up. Owners are told before a paid plan ends, when it renews, when a payment fails and when it has ended.
*/

beforeEach(function () {
    $this->travelTo('2026-10-12 08:00:00');
    $this->push = new RecordingPushSender;
    app()->instance(PushSender::class, $this->push);
});

function centreAdmin(string $role = 'super_admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** An owner with a phone registered, on Pro paid by bank transfer until [$until]. @return array{0: User, 1: Tenant} */
function payingOwner(string $until = '2026-10-19 08:00:00', array $origin = ['source' => 'manual', 'auto_renew' => false]): array
{
    [$owner, $tenant] = apiOwner(['name' => 'Al-Fares Electronics']);
    test()->postJson('/api/v1/push-tokens', ['token' => 'owner-phone', 'platform' => 'ios', 'app_version' => '1.24.1'])->assertCreated();
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), 'active', now()->parse($until), $origin);

    return [$owner, $tenant];
}

function tell(User $user, Tenant $tenant, string $type): void
{
    app(CurrentTenant::class)->use($tenant, fn () => app(Notifier::class)->notify($user, new PushMessage($type, 'Title', 'Body', ['route' => '/plans']), []));
}

/** E-mail through the owner's SMTP server, as saved in /admin/mail. */
function mailReady(): void
{
    app(MailSettings::class)->save(['host' => 'smtp.example.test', 'port' => 587, 'security' => 'starttls', 'username' => 'u', 'password' => 'p', 'from_address' => 'noreply@qistas.test', 'from_name' => 'Qistas'], null);
}

function inbox(User $user, string $type): int
{
    return AppNotification::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('type', $type)->count();
}

describe('the channels', function () {
    it('puts every notice in the app (the inbox and a push to each phone) by default', function () {
        [$owner, $tenant] = payingOwner();

        tell($owner, $tenant, 'billing.approved');

        expect(inbox($owner, 'billing.approved'))->toBe(1)->and($this->push->to('owner-phone'))->toHaveCount(1);
    });

    it('also e-mails a notice once e-mail is set up and the address is verified', function () {
        [$owner, $tenant] = payingOwner();
        Mail::fake();

        tell($owner, $tenant, 'billing.renewal_due');
        Mail::assertNothingQueued();

        mailReady();
        tell($owner, $tenant, 'billing.renewal_due');
        Mail::assertQueued(NoticeMail::class, fn (NoticeMail $mail) => $mail->hasTo($owner->email) && $mail->notice->type === 'billing.renewal_due');

        $owner->forceFill(['email_verified_at' => null])->save();
        tell($owner, $tenant, 'billing.expired');
        Mail::assertQueued(NoticeMail::class, 1);
    });

    it('never sends a text without an SMS provider, whatever was chosen', function () {
        [$owner, $tenant] = payingOwner();
        $texts = new class implements SmsSender
        {
            public array $sent = [];

            public function available(): bool
            {
                return false;
            }

            public function send(string $to, string $text): bool
            {
                $this->sent[] = $text;

                return true;
            }
        };
        app()->instance(SmsSender::class, $texts);
        $owner->forceFill(['phone' => '+966501234567']);

        $this->putJson('/api/v1/notifications/preferences', ['billing_expired' => ['channels' => ['sms' => true]]])->assertOk()
            ->assertJsonPath('data.billing_expired.channels.sms', true)
            ->assertJsonPath('meta.channels.sms.available', false)
            ->assertJsonPath('meta.channels.push.available', true);
        tell($owner, $tenant, 'billing.expired');

        expect($texts->sent)->toBe([])->and(inbox($owner, 'billing.expired'))->toBe(1);
    });
});

describe('what each person chooses', function () {
    it('keeps a choice per kind and per channel', function () {
        [$owner, $tenant] = payingOwner();
        mailReady();
        Mail::fake();

        $this->putJson('/api/v1/notifications/preferences', [
            'billing_renewed' => ['channels' => ['push' => false, 'email' => true]],
            'support_reply' => ['enabled' => false],
        ])->assertOk()
            ->assertJsonPath('data.billing_renewed.channels', ['push' => false, 'email' => true, 'sms' => false])
            ->assertJsonPath('data.support_reply.enabled', false);

        tell($owner, $tenant, 'billing.approved');
        tell($owner, $tenant, 'support.reply');

        expect(inbox($owner, 'billing.approved'))->toBe(0)->and(inbox($owner, 'support.reply'))->toBe(0)
            ->and($this->push->sent)->toBe([]);
        Mail::assertQueued(NoticeMail::class, 1);
    });

    it('chooses on the web too', function () {
        [$owner] = owner();
        mailReady();

        $this->actingAs($owner)->get(route('app.settings.notifications'))->assertOk()
            ->assertSee('Before your plan ends')->assertSee('By e-mail')->assertSee('By text message');

        $this->actingAs($owner)->put(route('app.settings.notifications.update'), [
            'kinds' => ['instalment_late' => ['enabled' => '1', 'channels' => ['push' => '1', 'email' => '1']]],
        ])->assertRedirect();

        Sanctum::actingAs($owner, ['app']);
        $this->getJson('/api/v1/notifications/preferences')->assertJsonPath('data.instalment_late.channels.email', true);
    });

    it('shows billing notices on the web to owners only', function () {
        [, $tenant] = owner();
        $collector = memberAs('collector', $tenant);

        $this->actingAs($collector)->get(route('app.settings.notifications'))->assertOk()
            ->assertDontSee('Before your plan ends')->assertSee('Support replies');
    });
});

describe('what the admin decides for everyone', function () {
    it('lets a super admin switch a kind off on any channel, for everyone', function () {
        [$owner, $tenant] = payingOwner();
        mailReady();
        Mail::fake();

        $this->actingAs(centreAdmin('admin'))->put(route('admin.notifications.update'), ['allowed' => []])->assertForbidden();
        $this->actingAs(centreAdmin())->put(route('admin.notifications.update'), [
            'allowed' => ['billing_renewed' => ['email' => '1'], 'billing_expired' => ['push' => '1', 'email' => '1']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        tell($owner, $tenant, 'billing.approved');
        expect(inbox($owner, 'billing.approved'))->toBe(0)->and($this->push->sent)->toBe([]);
        Mail::assertQueued(NoticeMail::class, 1);

        Sanctum::actingAs($owner, ['app']);
        $this->getJson('/api/v1/notifications/preferences')->assertJsonPath('meta.allowed.billing_renewed', ['push' => false, 'email' => true, 'sms' => false]);
    });

    it('keeps the Firebase key for pushes in the admin, encrypted and never shown back', function () {
        $admin = centreAdmin();
        $account = json_encode(['type' => 'service_account', 'project_id' => 'qistas-app', 'client_email' => 'push@qistas-app.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nsecret-key-body\n-----END PRIVATE KEY-----\n", 'token_uri' => 'https://oauth2.googleapis.com/token']);

        $this->actingAs($admin)->put(route('admin.notifications.push'), ['service_account' => '{"not": "an account"}'])->assertSessionHasErrors('service_account');
        $this->actingAs($admin)->put(route('admin.notifications.push'), ['service_account' => $account])->assertRedirect()->assertSessionHasNoErrors();

        expect((string) PlatformSetting::query()->find(PushSettings::KEY)?->value)->not->toContain('secret-key-body');
        app()->forgetInstance(PushSender::class);
        expect(app(PushSender::class))->toBeInstanceOf(FcmPushSender::class);
        $this->actingAs($admin)->get(route('admin.notifications.index'))->assertOk()->assertDontSee('secret-key-body')->assertSee('qistas-app');
    });

    it('shows which channels are ready', function () {
        $this->actingAs(centreAdmin())->get(route('admin.notifications.index'))->assertOk()
            ->assertSee('Pushes are written to the log until the Firebase key is saved')
            ->assertSee('No SMS provider yet');
        expect(app(Channels::class)->available())->toBe(['push' => true, 'email' => false, 'sms' => false]);
    });
});

describe('billing notices', function () {
    it('reminds the owner seven days and one day before a bank-transfer plan ends, once each', function () {
        [$owner] = payingOwner('2026-10-19 08:00:00');

        app(BillingNotices::class)->run();
        app(BillingNotices::class)->run();
        expect(inbox($owner, 'billing.renewal_due'))->toBe(1);

        $this->travelTo('2026-10-18 09:00:00');
        app(BillingNotices::class)->run();
        expect(inbox($owner, 'billing.renewal_due'))->toBe(2);
        expect($this->push->to('owner-phone')[1]->body)->toContain('19 October 2026');
    });

    it('says nothing ahead of a subscription that renews by itself', function () {
        [$owner] = payingOwner('2026-10-19 08:00:00', ['source' => 'play', 'auto_renew' => true]);

        app(BillingNotices::class)->run();

        expect(inbox($owner, 'billing.renewal_due'))->toBe(0);
    });

    it('tells the owner once when the plan has ended and the workspace is on Free, nothing deleted', function () {
        [$owner] = payingOwner('2026-10-13 08:00:00');
        $this->travelTo(now()->addDays(2 + (int) config('qistas.billing.grace_days')));

        app(BillingNotices::class)->run();
        app(BillingNotices::class)->run();

        expect(inbox($owner, 'billing.expired'))->toBe(1)
            ->and(AppNotification::query()->withoutGlobalScopes()->where('type', 'billing.expired')->value('body'))->toContain('Nothing is deleted');
    });

    it('tells the owner when the store renewed the plan, or could not take the payment', function () {
        [$owner, $tenant] = payingOwner('2026-11-12 08:00:00', ['source' => 'play', 'auto_renew' => true]);
        $subscription = $tenant->subscription()->sole();

        app(BillingNotices::class)->storeEvent($subscription, 'renewed');
        app(BillingNotices::class)->storeEvent($subscription, 'renewed');
        app(BillingNotices::class)->storeEvent($subscription, 'grace');

        expect(inbox($owner, 'billing.renewed'))->toBe(1)->and(inbox($owner, 'billing.payment_failed'))->toBe(1);
    });

    it('runs with the scheduled work', function () {
        payingOwner('2026-10-19 08:00:00');

        $this->artisan('qistas:billing-notices')->assertSuccessful();

        expect(AppNotification::query()->withoutGlobalScopes()->where('type', 'billing.renewal_due')->count())->toBe(1);
    });
});
