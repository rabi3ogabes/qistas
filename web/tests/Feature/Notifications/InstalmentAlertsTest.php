<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AppNotification;
use App\Models\Contract;
use App\Models\NotificationLogEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Push\PushSender;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RecordingPushSender;

/*
| Win Plan PP9: at nine every morning (in the workspace's own time zone) each person is told who pays today and who is
| late; an alert for each instalment on its due date and after its grace days, repeating as the person chose and
| stopping once it is paid. Nothing is sent while the platform has the switch off.
|
| The workspace is in Saudi Arabia (Asia/Riyadh, UTC+3): 06:00 UTC is 09:00 there.
*/

beforeEach(function () {
    $this->travelTo('2026-10-11 05:00:00');
    $this->push = new RecordingPushSender;
    app()->instance(PushSender::class, $this->push);
    switchOn(Feature::InstalmentAlerts);
    switchOn(Feature::DailyDigest);
});

/**
 * An owner with a phone registered, one instalment due today (Amal, 100.00) and one ten days late (Badr, 100.00).
 *
 * @return array{0: User, 1: Tenant, 2: Contract, 3: Contract}
 */
function alertsWorkspace(): array
{
    [$owner, $tenant] = apiOwner(['name' => 'Al-Fares Electronics']);
    test()->postJson('/api/v1/push-tokens', ['token' => 'owner-phone', 'platform' => 'android', 'app_version' => '1.13.0'])->assertCreated();
    $today = openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Amal'])->id, 'principal' => '300.00', 'start_date' => '2026-09-11', 'first_due_date' => '2026-10-11']);
    $late = openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Badr'])->id, 'principal' => '300.00', 'start_date' => '2026-09-01', 'first_due_date' => '2026-10-01']);

    return [$owner, $tenant, $today, $late];
}

/** Money is written with a non-breaking space after the currency code, so it never wraps; read it as a plain one. */
function plain(string $text): string
{
    return str_replace("\u{00A0}", ' ', $text);
}

function runAlerts(): void
{
    test()->artisan('qistas:send-digests')->assertSuccessful();
    test()->artisan('qistas:instalment-alerts')->assertSuccessful();
}

/** @param  array<string, mixed>  $preferences */
function preferAs(User $user, array $preferences): void
{
    Sanctum::actingAs($user, ['app']);
    test()->putJson('/api/v1/notifications/preferences', $preferences)->assertOk();
}

describe('the morning summary', function () {
    it('comes once a day at nine in the workspace’s time zone, with what is due and what is late', function () {
        [$owner] = alertsWorkspace();

        $this->travelTo('2026-10-11 05:55:00');
        runAlerts();
        expect($this->push->to('owner-phone'))->toBe([]);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();
        $this->travelTo('2026-10-11 06:05:00');
        runAlerts();

        $digests = array_values(array_filter($this->push->to('owner-phone'), fn ($m) => $m->type === 'daily_digest'));
        expect($digests)->toHaveCount(1)
            ->and(plain($digests[0]->body))->toBe('Due today: 1 (SAR 100.00). Late: 1 (SAR 100.00).');

        $this->travelTo('2026-10-12 06:00:00');
        runAlerts();
        expect(array_filter($this->push->to('owner-phone'), fn ($m) => $m->type === 'daily_digest'))->toHaveCount(2);
    });

    it('comes at each person’s own time, and lands in their inbox too', function () {
        [$owner, $tenant] = alertsWorkspace();
        $collector = memberAs('collector', $tenant);
        preferAs($collector, ['daily_digest' => ['enabled' => true, 'time' => '07:30']]);
        Sanctum::actingAs($collector, ['app']);
        $this->postJson('/api/v1/push-tokens', ['token' => 'collector-phone', 'platform' => 'android'])->assertCreated();

        $this->travelTo('2026-10-11 04:30:00');
        runAlerts();

        expect(collect($this->push->to('collector-phone'))->where('type', 'daily_digest'))->toHaveCount(1)
            ->and($this->push->to('owner-phone'))->toBe([])
            ->and(AppNotification::withoutGlobalScopes()->where('user_id', $collector->id)->where('type', 'daily_digest')->count())->toBe(1);
    });

    it('says when customers are past the workspace’s late limit', function () {
        [$owner, $tenant] = alertsWorkspace();
        $this->putJson('/api/v1/settings/tools/alerts.late_after_days', ['value' => 7])->assertOk();

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        $digest = collect($this->push->to('owner-phone'))->firstWhere('type', 'daily_digest');
        expect(plain($digest->body))->toBe('Due today: 1 (SAR 100.00). Late: 1 (SAR 100.00). Past your late limit: 1.');
    });

    it('says nothing on a day with nothing due and nobody late', function () {
        [$owner] = apiOwner();
        $this->postJson('/api/v1/push-tokens', ['token' => 'owner-phone', 'platform' => 'android'])->assertCreated();

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        expect($this->push->sent)->toBe([]);
    });

    it('waits for the end of quiet hours', function () {
        [$owner] = alertsWorkspace();
        preferAs($owner, ['quiet_hours' => ['enabled' => true, 'from' => '08:00', 'to' => '10:00']]);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();
        expect($this->push->sent)->toBe([]);

        $this->travelTo('2026-10-11 07:00:00');
        runAlerts();
        expect(collect($this->push->to('owner-phone'))->where('type', 'daily_digest'))->toHaveCount(1);
    });

    it('never goes to a viewer, nor to a person who turned it off', function () {
        [$owner, $tenant] = alertsWorkspace();
        $viewer = memberAs('viewer', $tenant);
        Sanctum::actingAs($viewer, ['app']);
        $this->postJson('/api/v1/push-tokens', ['token' => 'viewer-phone', 'platform' => 'android'])->assertCreated();
        preferAs($owner, ['daily_digest' => ['enabled' => false]]);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        expect($this->push->to('viewer-phone'))->toBe([])
            ->and(collect($this->push->to('owner-phone'))->where('type', 'daily_digest'))->toHaveCount(0);
    });
});

describe('instalment alerts', function () {
    it('alerts on the due date, then after the grace days, repeating weekly by default, and stops once paid', function () {
        [$owner, $tenant, $today] = alertsWorkspace();
        preferAs($owner, ['instalment_due' => ['enabled' => true], 'daily_digest' => ['enabled' => false]]);
        $installment = installmentsOfContract($today)->first();
        $keysFor = fn () => NotificationLogEntry::withoutGlobalScopes()->where('subject_id', $installment->id)->where('status', 'sent')->pluck('type')->all();

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();
        expect($keysFor())->toBe(['instalment_due']);

        // Grace days 0: late from the next day.
        $this->travelTo('2026-10-12 06:00:00');
        runAlerts();
        foreach (range(13, 18) as $day) {
            $this->travelTo("2026-10-{$day} 06:00:00");
            runAlerts();
        }
        expect($keysFor())->toBe(['instalment_due', 'instalment_late']);

        $this->travelTo('2026-10-19 06:00:00');
        runAlerts();
        expect($keysFor())->toBe(['instalment_due', 'instalment_late', 'instalment_late']);

        app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($today->id), '100.00', 'cash', by: $owner);
        $this->travelTo('2026-10-26 06:00:00');
        runAlerts();
        expect($keysFor())->toHaveCount(3);
    });

    it('waits for the grace days before calling an instalment late', function () {
        switchOn(Feature::FlexibleSchedules);
        [$owner, $tenant] = alertsWorkspace();
        preferAs($owner, ['instalment_late' => ['enabled' => true, 'repeat' => 'daily'], 'daily_digest' => ['enabled' => false]]);
        $graced = openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Carim'])->id, 'start_date' => '2026-09-08', 'first_due_date' => '2026-10-08', 'grace_days' => 5]);
        $installment = installmentsOfContract($graced)->first();
        $lateAlerts = fn () => NotificationLogEntry::withoutGlobalScopes()->where('subject_id', $installment->id)->where('type', 'instalment_late')->count();

        $this->travelTo('2026-10-13 06:00:00');
        runAlerts();
        expect($lateAlerts())->toBe(0);

        $this->travelTo('2026-10-14 06:00:00');
        runAlerts();
        $this->travelTo('2026-10-15 06:00:00');
        runAlerts();
        expect($lateAlerts())->toBe(2);
    });

    it('repeats monthly when asked', function () {
        [$owner, $tenant, , $late] = alertsWorkspace();
        preferAs($owner, ['instalment_late' => ['enabled' => true, 'repeat' => 'monthly'], 'daily_digest' => ['enabled' => false]]);
        $installment = installmentsOfContract($late)->first();
        $count = fn () => NotificationLogEntry::withoutGlobalScopes()->where('subject_id', $installment->id)->where('type', 'instalment_late')->count();

        foreach (['2026-10-11', '2026-10-20', '2026-10-31', '2026-11-01'] as $day) {
            $this->travelTo("{$day} 06:00:00");
            runAlerts();
        }

        // Late from 2 October: first alert, then again 30 days after it began (1 November).
        expect($count())->toBe(2);
    });

    it('gathers several instalments into one push that names them', function () {
        [$owner, $tenant] = alertsWorkspace();
        openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Dalia'])->id, 'start_date' => '2026-09-11', 'first_due_date' => '2026-10-11']);
        preferAs($owner, ['instalment_due' => ['enabled' => true], 'daily_digest' => ['enabled' => false], 'instalment_late' => ['enabled' => false]]);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        $pushes = $this->push->to('owner-phone');
        expect($pushes)->toHaveCount(1)
            ->and($pushes[0]->title)->toBe('Instalments due today: 2')
            ->and($pushes[0]->body)->toBe('Amal, Dalia');
    });

    it('names the customer when there is just one', function () {
        [$owner] = alertsWorkspace();
        preferAs($owner, ['instalment_late' => ['enabled' => true], 'daily_digest' => ['enabled' => false]]);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        $push = $this->push->to('owner-phone')[0];
        expect($push->title)->toBe('Late: Badr')
            ->and(plain($push->body))->toBe('SAR 100.00 for contract C-0002 has been late since 1 October 2026.');
    });
});

describe('the platform switch', function () {
    it('sends nothing at all while the alerts are switched off', function () {
        alertsWorkspace();
        app(FeatureControl::class)->setState(Feature::InstalmentAlerts, PlatformState::Off, null, null);
        app(FeatureControl::class)->setState(Feature::DailyDigest, PlatformState::Off, null, null);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        expect($this->push->sent)->toBe([])
            ->and(AppNotification::withoutGlobalScopes()->count())->toBe(0)
            ->and(NotificationLogEntry::withoutGlobalScopes()->count())->toBe(0);
    });

    it('lets the admin switch the summary off and keep the instalment alerts', function () {
        [$owner] = alertsWorkspace();
        preferAs($owner, ['instalment_due' => ['enabled' => true]]);
        app(FeatureControl::class)->setState(Feature::DailyDigest, PlatformState::Off, null, null);

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        expect(collect($this->push->to('owner-phone'))->pluck('type')->unique()->values()->all())->toBe(['instalment_due', 'instalment_late']);
    });

    it('never pushes to a phone that signed out', function () {
        [$owner] = alertsWorkspace();
        $this->deleteJson('/api/v1/push-tokens/owner-phone')->assertNoContent();

        $this->travelTo('2026-10-11 06:00:00');
        runAlerts();

        expect($this->push->sent)->toBe([])
            ->and(AppNotification::withoutGlobalScopes()->where('user_id', $owner->id)->count())->toBeGreaterThan(0);
    });
});
