<?php

use App\Entitlements\Feature;
use App\Models\AppNotification;
use App\Models\NotificationLogEntry;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\GlanceRefresh;
use App\Notifications\Preferences;
use App\Notifications\Push\LogPushSender;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingPushSender;

/*
| Daily-habit wave A, H3: once a day, at each person's morning-summary time on the workspace's clock, every phone they
| use gets one silent (data-only) push, so the home-screen widget is fresh before the owner opens the app. It shows
| nothing, writes no inbox entry, and does nothing at all while the platform has the home widget switched off.
|
| The workspace is in Saudi Arabia (Asia/Riyadh, UTC+3): 06:00 UTC is 09:00 there, the default summary time.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 05:00:00');
    $this->push = new RecordingPushSender;
    app()->instance(PushSender::class, $this->push);
    switchOn(Feature::HomeWidget);
});

/** One phone of $user, in $tenant; a revoked one is a phone the person signed out of. */
function glancePhone(User $user, Tenant $tenant, string $token, bool $revoked = false): PushToken
{
    return asTenant($tenant, fn () => PushToken::create([
        'user_id' => $user->id, 'token' => $token, 'platform' => 'android', 'revoked_at' => $revoked ? now() : null,
    ]));
}

function runGlanceRefresh(): void
{
    test()->artisan('qistas:send-glance-refresh')->assertSuccessful();
}

/** A throwaway Firebase service account with a fresh RSA key, as Google hands out. */
function glanceFcmAccount(): array
{
    // PHP on Windows finds no OpenSSL config of its own; it ships one beside the binary.
    $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($bundled) ? ['config' => $bundled] : []);
    $key = openssl_pkey_new($options);
    openssl_pkey_export($key, $pem, null, $options);

    return [
        'type' => 'service_account', 'project_id' => 'qistas-test', 'private_key' => $pem,
        'client_email' => 'push@qistas-test.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token',
    ];
}

describe('the morning refresh', function () {
    it('sends one silent push to each phone at the person’s digest time and not before', function () {
        [$owner, $tenant] = owner();
        $collector = memberAs('collector', $tenant);
        asTenant($tenant, fn () => Preferences::update($collector->id, ['daily_digest' => ['time' => '08:30']]));
        glancePhone($owner, $tenant, 'owner-phone');
        glancePhone($owner, $tenant, 'owner-tablet');
        glancePhone($collector, $tenant, 'collector-phone');

        runGlanceRefresh();
        expect($this->push->sent)->toBe([]);

        $this->travelTo('2026-10-11 05:30:00');
        runGlanceRefresh();
        expect($this->push->to('collector-phone'))->toHaveCount(1)
            ->and($this->push->to('owner-phone'))->toBe([]);

        $this->travelTo('2026-10-11 06:00:00');
        runGlanceRefresh();
        $this->travelTo('2026-10-11 06:05:00');
        runGlanceRefresh();

        expect($this->push->to('owner-phone'))->toHaveCount(1)
            ->and($this->push->to('owner-tablet'))->toHaveCount(1)
            ->and($this->push->to('collector-phone'))->toHaveCount(1);

        $message = $this->push->to('owner-phone')[0];
        expect($message->type)->toBe('glance.refresh')
            ->and($message->silent)->toBeTrue()
            ->and($message->title)->toBe('')
            ->and($message->body)->toBe('')
            ->and($message->data)->toBe([]);
    });

    it('never sends twice on the same day', function () {
        [$owner, $tenant] = owner();
        glancePhone($owner, $tenant, 'owner-phone');

        $this->travelTo('2026-10-11 06:00:00');
        expect(app(GlanceRefresh::class)->run($tenant, now()))->toBe(1)
            ->and(app(GlanceRefresh::class)->run($tenant, now()))->toBe(0);

        // Still inside the four hours after the person's time.
        $this->travelTo('2026-10-11 08:00:00');
        expect(app(GlanceRefresh::class)->run($tenant, now()))->toBe(0)
            ->and($this->push->to('owner-phone'))->toHaveCount(1);

        $this->travelTo('2026-10-12 06:00:00');
        expect(app(GlanceRefresh::class)->run($tenant, now()))->toBe(1)
            ->and($this->push->to('owner-phone'))->toHaveCount(2);
    });

    it('keeps its guard for a day and a half, as a plain value', function () {
        [$owner, $tenant] = owner();
        glancePhone($owner, $tenant, 'owner-phone');
        $this->travelTo('2026-10-11 06:00:00');
        app(GlanceRefresh::class)->run($tenant, now());

        $key = "glance:{$tenant->id}:{$owner->id}:2026-10-11";
        expect(is_scalar(Cache::get($key)))->toBeTrue();

        $this->travelTo('2026-10-12 17:00:00');
        expect(Cache::has($key))->toBeTrue();

        $this->travelTo('2026-10-12 19:00:00');
        expect(Cache::has($key))->toBeFalse();
    });

    it('counts the day on the workspace’s own clock, not UTC’s', function () {
        [$owner, $tenant] = owner();
        asTenant($tenant, fn () => Preferences::update($owner->id, ['daily_digest' => ['time' => '00:00']]));
        glancePhone($owner, $tenant, 'owner-phone');

        // 21:10 UTC on the 11th is ten past midnight on the 12th in Riyadh.
        $this->travelTo('2026-10-11 21:10:00');
        expect(app(GlanceRefresh::class)->run($tenant, now()))->toBe(1)
            ->and(Cache::has("glance:{$tenant->id}:{$owner->id}:2026-10-12"))->toBeTrue()
            ->and(Cache::has("glance:{$tenant->id}:{$owner->id}:2026-10-11"))->toBeFalse();
    });

    it('tells a person who works in two workspaces in each of them', function () {
        [$owner, $first] = owner();
        [, $second] = owner();
        $second->users()->attach($owner->id, ['role' => 'collector']);
        glancePhone($owner, $first, 'first-phone');
        glancePhone($owner, $second, 'second-phone');

        $this->travelTo('2026-10-11 06:00:00');
        runGlanceRefresh();

        expect($this->push->to('first-phone'))->toHaveCount(1)
            ->and($this->push->to('second-phone'))->toHaveCount(1);
    });

    it('does nothing when the feature is off', function () {
        Queue::fake();
        [$owner, $tenant] = owner();
        glancePhone($owner, $tenant, 'owner-phone');
        switchOff(Feature::HomeWidget);

        $this->travelTo('2026-10-11 06:00:00');
        runGlanceRefresh();
        expect($this->push->sent)->toBe([])
            ->and(Cache::has("glance:{$owner->id}:2026-10-11"))->toBeFalse();
        Queue::assertNothingPushed();

        // Asked at every run, and an off run used up nothing: switched back on, the same morning still goes out.
        switchOn(Feature::HomeWidget);
        $this->travelTo('2026-10-11 06:05:00');
        runGlanceRefresh();
        expect($this->push->to('owner-phone'))->toHaveCount(1);
    });

    it('sends no notification block', function () {
        config(['qistas.push.fcm_credentials' => base64_encode(json_encode(glanceFcmAccount()))]);
        app()->forgetInstance(PushSender::class);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/qistas-test/messages/1']),
        ]);
        [$owner, $tenant] = owner();
        glancePhone($owner, $tenant, 'owner-phone');

        $this->travelTo('2026-10-11 06:00:00');
        expect(app(GlanceRefresh::class)->run($tenant, now()))->toBe(1);

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/qistas-test/messages:send') {
                return false;
            }
            $message = $request['message'];

            return $message['token'] === 'owner-phone'
                && ! array_key_exists('notification', $message)
                && $message['data'] === ['type' => 'glance.refresh']
                && $message['android']['priority'] === 'high'
                // An iPhone wakes the app in the background only for content-available, and never rings for it.
                && $message['apns']['payload']['aps'] === ['content-available' => 1];
        });
    });

    it('leaves no inbox entry', function () {
        [$owner, $tenant] = owner();
        glancePhone($owner, $tenant, 'owner-phone');
        $before = AppNotification::withoutGlobalScopes()->count();

        $this->travelTo('2026-10-11 06:00:00');
        runGlanceRefresh();

        expect($this->push->to('owner-phone'))->toHaveCount(1)
            ->and(AppNotification::withoutGlobalScopes()->count())->toBe($before)
            ->and(NotificationLogEntry::withoutGlobalScopes()->count())->toBe(0);
    });

    it('skips revoked phones and other workspaces’ people', function () {
        [$owner, $tenant] = owner();
        glancePhone($owner, $tenant, 'owner-phone');
        glancePhone($owner, $tenant, 'owner-old-phone', revoked: true);
        $signedOut = memberAs('collector', $tenant);
        glancePhone($signedOut, $tenant, 'signed-out-phone', revoked: true);
        $viewer = memberAs('viewer', $tenant);
        glancePhone($viewer, $tenant, 'viewer-phone');
        [$stranger, $other] = owner();
        glancePhone($stranger, $other, 'stranger-phone');

        $this->travelTo('2026-10-11 06:00:00');
        expect(app(GlanceRefresh::class)->run($tenant, now()))->toBe(1)
            ->and($this->push->to('owner-phone'))->toHaveCount(1)
            ->and($this->push->to('owner-old-phone'))->toBe([])
            ->and($this->push->to('signed-out-phone'))->toBe([])
            ->and($this->push->to('viewer-phone'))->toBe([])
            ->and($this->push->to('stranger-phone'))->toBe([]);

        // A person with no phone left to tell used up nothing: the other workspace is told on its own run.
        expect(Cache::has("glance:{$signedOut->id}:2026-10-11"))->toBeFalse()
            ->and(app(GlanceRefresh::class)->run($other, now()))->toBe(1)
            ->and($this->push->to('stranger-phone'))->toHaveCount(1);
    });

    it('leaves demo and suspended workspaces alone', function () {
        [$demoOwner, $demo] = owner(['is_demo' => true]);
        [$suspendedOwner, $suspended] = owner(['status' => 'suspended']);
        glancePhone($demoOwner, $demo, 'demo-phone');
        glancePhone($suspendedOwner, $suspended, 'suspended-phone');

        $this->travelTo('2026-10-11 06:00:00');
        runGlanceRefresh();

        expect($this->push->sent)->toBe([]);
    });

    it('runs from the scheduler every five minutes, one run at a time', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'qistas:send-glance-refresh'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('*/5 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue();
    });
});

describe('the silent message', function () {
    it('carries its type and data and shows nothing', function () {
        $message = PushMessage::silent('glance.refresh', ['route' => '/']);

        expect($message->silent)->toBeTrue()
            ->and($message->type)->toBe('glance.refresh')
            ->and($message->title)->toBe('')
            ->and($message->body)->toBe('')
            ->and($message->data)->toBe(['route' => '/'])
            ->and((new PushMessage('daily_digest', 'T', 'B'))->silent)->toBeFalse();
    });

    it('is logged by its type alone while Firebase is not set up', function () {
        Log::spy();
        [$owner, $tenant] = owner();
        $phone = glancePhone($owner, $tenant, 'owner-phone');

        expect((new LogPushSender)->send($phone, PushMessage::silent('glance.refresh')))->toBeTrue();

        Log::shouldHaveReceived('info')->withArgs(fn (string $line, array $context) => $context['type'] === 'glance.refresh'
            && ! array_key_exists('title', $context) && ! array_key_exists('body', $context))->once();
    });
});
