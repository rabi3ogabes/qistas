<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AppNotification;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

/*
| Win Plan PP9, the parts a phone talks to: registering for pushes, each person's choices, the inbox, the remind-all
| checklist with every message written out, and the business's own reminder wording in five languages.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 09:00:00');
    switchOn(Feature::InstalmentAlerts);
});

describe('push tokens', function () {
    it('registers a phone once, and moves it to whoever signs in on it next', function () {
        [$owner, $tenant] = apiOwner();

        $this->postJson('/api/v1/push-tokens', ['token' => 'phone-1', 'platform' => 'android', 'app_version' => '1.13.0'])->assertCreated();
        $this->postJson('/api/v1/push-tokens', ['token' => 'phone-1', 'platform' => 'android', 'app_version' => '1.13.1'])->assertOk();

        $member = apiMember('collector', $tenant);
        $this->postJson('/api/v1/push-tokens', ['token' => 'phone-1', 'platform' => 'android'])->assertOk();

        $tokens = PushToken::withoutGlobalScopes()->get();
        expect($tokens)->toHaveCount(1)->and($tokens[0]->user_id)->toBe($member->id);
    });

    it('leaves the business it was in when another business registers the same phone', function () {
        [$first] = apiOwner();
        $this->postJson('/api/v1/push-tokens', ['token' => 'shared-phone', 'platform' => 'android'])->assertCreated();

        [$second, $other] = apiOwner();
        $this->postJson('/api/v1/push-tokens', ['token' => 'shared-phone', 'platform' => 'android'])->assertCreated();

        $tokens = PushToken::withoutGlobalScopes()->where('token', 'shared-phone')->get();
        expect($tokens)->toHaveCount(1)->and($tokens[0]->tenant_id)->toBe($other->id)->and($tokens[0]->user_id)->toBe($second->id);
    });

    it('refuses a platform it does not know', function () {
        apiOwner();

        $this->postJson('/api/v1/push-tokens', ['token' => 'phone-1', 'platform' => 'fax'])->assertStatus(422);
    });

    it('lets a person remove only their own phone', function () {
        [$owner, $tenant] = apiOwner();
        $this->postJson('/api/v1/push-tokens', ['token' => 'owner-phone', 'platform' => 'android'])->assertCreated();
        apiMember('collector', $tenant);

        $this->deleteJson('/api/v1/push-tokens/owner-phone')->assertNotFound();
        expect(PushToken::withoutGlobalScopes()->whereNull('revoked_at')->count())->toBe(1);
    });
});

describe('preferences', function () {
    it('starts with the summary at nine, late alerts weekly, due-date alerts off and no quiet hours', function () {
        apiOwner();

        $this->getJson('/api/v1/notifications/preferences')->assertOk()->assertExactJson(['data' => [
            'daily_digest' => ['enabled' => true, 'time' => '09:00'],
            'instalment_due' => ['enabled' => false],
            'instalment_late' => ['enabled' => true, 'repeat' => 'weekly'],
            'quiet_hours' => ['enabled' => false, 'from' => '22:00', 'to' => '08:00'],
        ]]);
    });

    it('keeps each person’s choices apart and checks them', function () {
        [$owner, $tenant] = apiOwner();
        $this->putJson('/api/v1/notifications/preferences', ['daily_digest' => ['time' => '07:15'], 'instalment_late' => ['repeat' => 'daily']])->assertOk()
            ->assertJsonPath('data.daily_digest', ['enabled' => true, 'time' => '07:15'])
            ->assertJsonPath('data.instalment_late.repeat', 'daily');

        $this->putJson('/api/v1/notifications/preferences', ['daily_digest' => ['time' => '25:00']])->assertStatus(422);
        $this->putJson('/api/v1/notifications/preferences', ['instalment_late' => ['repeat' => 'hourly']])->assertStatus(422);
        $this->putJson('/api/v1/notifications/preferences', ['quiet_hours' => ['enabled' => true, 'from' => '23:00', 'to' => '6am']])->assertStatus(422);

        apiMember('collector', $tenant);
        $this->getJson('/api/v1/notifications/preferences')->assertJsonPath('data.daily_digest.time', '09:00');
    });
});

describe('the inbox', function () {
    it('lists a person’s own alerts, newest first, and marks them read', function () {
        [$owner, $tenant] = apiOwner();
        $other = memberAs('manager', $tenant);
        app(CurrentTenant::class)->use($tenant, function () use ($owner, $other) {
            AppNotification::create(['user_id' => $owner->id, 'type' => 'daily_digest', 'title' => 'Older', 'body' => 'b', 'created_at' => now()->subHour()]);
            AppNotification::create(['user_id' => $owner->id, 'type' => 'instalment_late', 'title' => 'Newer', 'body' => 'b']);
            AppNotification::create(['user_id' => $other->id, 'type' => 'daily_digest', 'title' => 'Not theirs', 'body' => 'b']);
        });

        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.title', 'Newer')->assertJsonPath('data.1.title', 'Older')
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.unread', 2);

        $this->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
        expect(AppNotification::withoutGlobalScopes()->where('user_id', $other->id)->whereNull('read_at')->count())->toBe(1);
    });
});

describe('remind everyone', function () {
    /** @return array{0: User, 1: Tenant} */
    function remindWorkspace(): array
    {
        [$owner, $tenant] = apiOwner(['name' => 'Al-Fares Electronics']);
        openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Amal', 'phone' => '0501112223'])->id, 'principal' => '300.00', 'start_date' => '2026-09-11', 'first_due_date' => '2026-10-11']);
        openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Badr', 'phone' => '+966502223334'])->id, 'principal' => '300.00', 'start_date' => '2026-09-01', 'first_due_date' => '2026-10-01']);

        return [$owner, $tenant];
    }

    it('lists exactly who the dashboard says pays today, each with the message written out', function () {
        remindWorkspace();

        $dashboard = array_column($this->getJson('/api/v1/dashboard')->json('data.due_today'), 'installment_id');
        $rows = $this->getJson('/api/v1/reminders/due-today')->assertOk()->json('data');

        expect(array_column($rows, 'installment_id'))->toBe($dashboard)
            ->and($rows[0]['customer_name'])->toBe('Amal')
            ->and($rows[0]['whatsapp'])->toBe('966501112223')
            ->and(str_replace("\u{00A0}", ' ', $rows[0]['message']))->toBe('Hello Amal, a friendly reminder that SAR 100.00 is due on 11 October 2026 for contract C-0001. Thank you. Al-Fares Electronics');
    });

    it('writes the firmer message for the late ones', function () {
        remindWorkspace();

        $rows = $this->getJson('/api/v1/reminders/due-today?scope=late')->assertOk()->json('data');

        expect($rows)->toHaveCount(1)
            ->and(str_replace("\u{00A0}", ' ', $rows[0]['message']))->toBe('Hello Badr, SAR 100.00 for contract C-0002 has been overdue since 1 October 2026. Could you settle it soon? Thank you. Al-Fares Electronics');
    });

    it('writes in the language asked for, falling back to the default wording in it', function () {
        remindWorkspace();

        $message = $this->getJson('/api/v1/reminders/due-today?language=ar')->json('data.0.message');

        expect($message)->toContain('مرحبًا Amal')->toContain('C-0001')->toContain('Al-Fares Electronics');
    });

    it('uses the business’s own wording where it has one, and the default elsewhere', function () {
        remindWorkspace();

        $this->putJson('/api/v1/message-templates/reminder_due', ['language' => 'en', 'body' => 'Dear :name, :amount on :date (:reference). :business'])->assertOk();

        expect(str_replace("\u{00A0}", ' ', $this->getJson('/api/v1/reminders/due-today')->json('data.0.message')))->toBe('Dear Amal, SAR 100.00 on 11 October 2026 (C-0001). Al-Fares Electronics')
            ->and($this->getJson('/api/v1/reminders/due-today?language=ar')->json('data.0.message'))->toContain('مرحبًا Amal');

        $this->putJson('/api/v1/message-templates/reminder_due', ['language' => 'en', 'body' => ''])->assertOk();
        expect($this->getJson('/api/v1/reminders/due-today')->json('data.0.message'))->toStartWith('Hello Amal, a friendly reminder');
    });

    it('lists each wording with its default, and only owners and managers change it', function () {
        [$owner, $tenant] = remindWorkspace();

        $templates = $this->getJson('/api/v1/message-templates')->assertOk()->json('data');
        expect(collect($templates)->where('key', 'reminder_late')->where('language', 'fr')->first())
            ->toMatchArray(['custom' => false])->and($templates)->toHaveCount(10);

        $this->putJson('/api/v1/message-templates/reminder_due', ['language' => 'xx', 'body' => 'Hi'])->assertStatus(422);
        $this->putJson('/api/v1/message-templates/reminder_due', ['language' => 'en', 'body' => str_repeat('a', 1001)])->assertStatus(422);
        $this->putJson('/api/v1/message-templates/not_a_key', ['language' => 'en', 'body' => 'Hi'])->assertNotFound();

        apiMember('collector', $tenant);
        $this->getJson('/api/v1/reminders/due-today')->assertOk();
        $this->putJson('/api/v1/message-templates/reminder_due', ['language' => 'en', 'body' => 'Hi'])->assertForbidden();
    });

    it('is closed while the platform has it switched off', function () {
        app(FeatureControl::class)->setState(Feature::InstalmentAlerts, PlatformState::Off, null, null);
        remindWorkspace();

        $this->getJson('/api/v1/reminders/due-today')->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });
});

it('keeps one business’s wording away from another', function () {
    [$owner] = apiOwner();
    $this->putJson('/api/v1/message-templates/reminder_due', ['language' => 'en', 'body' => 'Ours :name'])->assertOk();

    apiOwner();
    expect(collect($this->getJson('/api/v1/message-templates')->json('data'))->where('key', 'reminder_due')->where('language', 'en')->first()['custom'])->toBeFalse();
});
