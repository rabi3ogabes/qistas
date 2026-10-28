<?php

use App\Entitlements\Feature;
use App\Mail\MailSettings;
use App\Mail\NoticeMail;
use App\Models\AppNotification;
use App\Models\DayClose;
use App\Models\NotificationLogEntry;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\PushToken;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Alerts\DayCloseDifference;
use App\Notifications\Alerts\DayCloseReminders;
use App\Notifications\Alerts\Notifier;
use App\Notifications\Catalog;
use App\Notifications\Channels;
use App\Notifications\Preferences;
use App\Notifications\Push\PushMessage;
use App\Notifications\Push\PushSender;
use App\Settings\TenantSettings;
use App\Support\Format;
use App\Support\InLanguage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RecordingPushSender;

/*
| Daily-habit wave A, H4: in the evening, from the workspace's closing time and for up to two hours (never past midnight), each person who may close
| the day is reminded once, when the day is still open and has money in it or something to look at; and when someone who
| is not an owner closes with a difference between the cash counted and the cash recorded, every owner is told once.
| Nothing goes while the platform has the end-of-day close switched off, and no notice ever names a customer.
|
| The workspace is in Riyadh (UTC+3, no daylight saving): the closing time, 20:00 unless the owner changed it, is 17:00 UTC.
| The clock stands at noon UTC on the 21st (15:00 in Riyadh), when the day's first payment is taken.
*/

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    $this->push = new RecordingPushSender;
    app()->instance(PushSender::class, $this->push);
    switchOn(Feature::DayClose);
    $this->shop = closeShop();
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->mona = closeStaff($this->shop, 'Mona Manager', 'manager');
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->abe = closeStaff($this->shop, 'Abe Accountant', 'accountant');
    $this->vera = closeStaff($this->shop, 'Vera Viewer', 'viewer');
    $this->contract = closeContract($this->shop);
    foreach (['boss' => $this->boss, 'mona' => $this->mona, 'sara' => $this->sara, 'abe' => $this->abe, 'vera' => $this->vera] as $name => $person) {
        dayClosePhone($person, $this->shop, "{$name}-phone");
    }
    $this->travelTo('2026-10-21 12:00:00');
    closeTake($this->contract, '100.00', by: $this->sara);
});

/** One phone of $user in $tenant. */
function dayClosePhone(User $user, Tenant $tenant, string $token): PushToken
{
    return asTenant($tenant, fn () => PushToken::create(['user_id' => $user->id, 'token' => $token, 'platform' => 'android']));
}

/** The reminders at [$utc], for the workspace of the test: how many people were told. */
function remindAt(string $utc, ?Tenant $tenant = null): int
{
    test()->travelTo($utc);

    return app(DayCloseReminders::class)->run($tenant ?? test()->shop, now());
}

function runDayCloseReminders(): void
{
    test()->artisan('qistas:send-day-close-reminders')->assertSuccessful();
}

/** @return list<PushMessage> every push of [$type] that reached any phone, oldest first */
function dayClosePushes(string $type): array
{
    return array_values(array_map(fn (array $push) => $push['message'], array_filter(test()->push->sent, fn (array $push) => $push['message']->type === $type)));
}

/** Money is written with a non-breaking space after the currency code, so it never wraps; read it as a plain one. */
function dayClosePlain(string $text): string
{
    return str_replace("\u{00A0}", ' ', $text);
}

/** What a person's inbox holds of [$type], oldest first. */
function dayCloseInbox(User $user, Tenant $tenant, string $type): array
{
    return asTenant($tenant, fn () => AppNotification::query()->where('user_id', $user->id)->where('type', $type)->orderBy('created_at')->get()->all());
}

/** A workspace of its own with an owner, a phone, and a contract opened today (something to look at). */
function dayCloseOtherShop(array $attributes = []): array
{
    $tenant = Tenant::factory()->create($attributes + ['country' => 'SA', 'timezone' => 'Asia/Riyadh']);
    $tenant->subscribeTo(Plan::where('key', 'free')->sole());
    $owner = closeStaff($tenant, 'Other Owner', 'owner');
    dayClosePhone($owner, $tenant, 'other-owner-phone-'.$tenant->id);
    closeContract($tenant, 'Badr Saleh');

    return [$tenant, $owner];
}

describe('the evening reminder', function () {
    it('reminds each person who can close at the closing time and not before', function () {
        $this->travelTo('2026-10-21 16:55:00');
        runDayCloseReminders();
        expect($this->push->sent)->toBe([]);

        $this->travelTo('2026-10-21 17:00:00');
        runDayCloseReminders();
        $this->travelTo('2026-10-21 17:05:00');
        runDayCloseReminders();

        // Owner, manager, collector and accountant may close a day (DayClosePolicy::create); a viewer may not.
        foreach (['boss', 'mona', 'sara', 'abe'] as $name) {
            expect($this->push->to("{$name}-phone"))->toHaveCount(1, $name);
        }
        $message = $this->push->to('sara-phone')[0];
        expect($message->type)->toBe('day_close_reminder')
            ->and($message->silent)->toBeFalse()
            ->and($message->title)->toBe(__('Time to close the day'))
            ->and($message->body)->toBe(__('Count the cash in the till and close today.'))
            ->and($this->push->to('vera-phone'))->toBe([])
            // It is a notice like the others: the inbox keeps it, and the log decides it once.
            ->and(dayCloseInbox($this->sara, $this->shop, 'day_close_reminder'))->toHaveCount(1)
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('type', 'day_close_reminder')->where('status', 'sent')->count()))->toBe(4);
    });

    it('does not remind after two hours', function () {
        // Two hours from 20:00 end at 22:00 (19:00 UTC), and the end itself is outside.
        expect(remindAt('2026-10-21 19:00:00'))->toBe(0)
            ->and(remindAt('2026-10-21 19:05:00'))->toBe(0)
            ->and($this->push->sent)->toBe([]);

        // That late run used nothing up: a minute earlier, the same evening still goes out.
        expect(remindAt('2026-10-21 18:59:00'))->toBe(4)
            ->and($this->push->to('sara-phone'))->toHaveCount(1);
    });

    it('does not remind twice', function () {
        expect(remindAt('2026-10-21 17:00:00'))->toBe(4)
            ->and(remindAt('2026-10-21 17:05:00'))->toBe(0)
            ->and(remindAt('2026-10-21 18:30:00'))->toBe(0)
            ->and($this->push->to('sara-phone'))->toHaveCount(1);

        // Once a day: the next evening, with money in it, they are reminded again.
        $this->travelTo('2026-10-22 12:00:00');
        closeTake($this->contract, '40.00', by: $this->sara);
        expect(remindAt('2026-10-22 17:00:00'))->toBe(4)
            ->and($this->push->to('sara-phone'))->toHaveCount(2);
    });

    it('says nothing on a day with no money and nothing to look at', function () {
        // Nothing was recorded on the 22nd: no payment, no void, no waived fee, no contract opened or cancelled.
        expect(remindAt('2026-10-22 17:00:00'))->toBe(0)
            ->and($this->push->sent)->toBe([])
            ->and(dayCloseInbox($this->sara, $this->shop, 'day_close_reminder'))->toBe([]);

        // The skip is recorded as the morning summary does it, once for each person who would have been told.
        $skips = asTenant($this->shop, fn () => NotificationLogEntry::query()->where('type', 'day_close_reminder')->get());
        expect($skips)->toHaveCount(4)
            ->and($skips->pluck('status')->unique()->all())->toBe(['skipped'])
            ->and($skips->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->boss, $this->mona, $this->sara, $this->abe])->pluck('id')->sort()->values()->all());

        expect(remindAt('2026-10-22 17:05:00'))->toBe(0)
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('type', 'day_close_reminder')->count()))->toBe(4);
    });

    it('speaks up on a day with only something to look at', function () {
        // No payment, but a contract was opened that day: the watch list is not empty.
        $this->travelTo('2026-10-22 12:00:00');
        closeContract($this->shop, 'Badr Saleh');

        expect(remindAt('2026-10-22 17:00:00'))->toBe(4);
    });

    it('does nothing when the feature is off', function () {
        switchOff(Feature::DayClose);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        expect(remindAt('2026-10-21 17:00:00'))->toBe(0);
        runDayCloseReminders();

        // Asked at every run, before anything about the day or the people is looked up.
        expect($this->push->sent)->toBe([])
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->count()))->toBe(0)
            ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'tenant_users') || str_contains($sql, '"transactions"'))->all())->toBe([]);

        // An off run used up nothing: switched back on, the same evening still goes out.
        switchOn(Feature::DayClose);
        expect(remindAt('2026-10-21 17:10:00'))->toBe(4);
    });

    it('does not remind once the day is closed', function () {
        closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        expect(remindAt('2026-10-21 17:00:00'))->toBe(0)
            ->and($this->push->sent)->toBe([])
            // A closed day's figures are not worked out for it.
            ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, '"transactions"') || str_contains($sql, 'tenant_users'))->all())->toBe([]);
    });

    it('reminds again when the day is reopened', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
        expect(remindAt('2026-10-21 17:00:00'))->toBe(0);

        reopenDay($close, 'The count was wrong', $this->boss);
        expect(remindAt('2026-10-21 17:10:00'))->toBe(4);
    });

    it('respects a person’s switch and quiet hours', function () {
        asTenant($this->shop, function () {
            Preferences::update($this->sara->id, ['day_close_reminder' => ['enabled' => false]]);
            Preferences::update($this->mona->id, ['quiet_hours' => ['enabled' => true, 'from' => '20:00', 'to' => '21:00']]);
        });

        // 20:00 in Riyadh: Sara switched the reminder off, and Mona's quiet hours have just begun.
        expect(remindAt('2026-10-21 17:00:00'))->toBe(2)
            ->and($this->push->to('boss-phone'))->toHaveCount(1)
            ->and($this->push->to('abe-phone'))->toHaveCount(1)
            ->and($this->push->to('sara-phone'))->toBe([])
            ->and($this->push->to('mona-phone'))->toBe([])
            ->and(dayCloseInbox($this->sara, $this->shop, 'day_close_reminder'))->toBe([]);

        // At 21:00 the quiet hours end, still inside the two hours: she is told then, once. Sara never is.
        expect(remindAt('2026-10-21 18:00:00'))->toBe(1)
            ->and(remindAt('2026-10-21 18:05:00'))->toBe(0)
            ->and($this->push->to('mona-phone'))->toHaveCount(1)
            ->and($this->push->to('sara-phone'))->toBe([]);
    });

    it('uses the workspace’s closing time setting', function () {
        TenantSettings::for($this->shop)->set('day_close.closing_time', '21:30', $this->boss);

        // 20:00 and 21:25 in Riyadh are before the closing time the owner chose.
        expect(remindAt('2026-10-21 17:00:00'))->toBe(0)
            ->and(remindAt('2026-10-21 18:25:00'))->toBe(0)
            ->and($this->push->sent)->toBe([]);

        expect(remindAt('2026-10-21 18:30:00'))->toBe(4);
    });

    it('counts the evening on each workspace’s own clock', function () {
        // Karachi is two hours ahead of Riyadh: its 20:00 is 15:00 UTC.
        [$karachi] = dayCloseOtherShop(['country' => 'PK', 'timezone' => 'Asia/Karachi']);

        $this->travelTo('2026-10-21 15:00:00');
        runDayCloseReminders();
        expect($this->push->to('other-owner-phone-'.$karachi->id))->toHaveCount(1)
            ->and($this->push->sent)->toHaveCount(1);

        $this->travelTo('2026-10-21 17:00:00');
        runDayCloseReminders();
        expect($this->push->to('sara-phone'))->toHaveCount(1)
            ->and($this->push->to('other-owner-phone-'.$karachi->id))->toHaveCount(1);
    });

    it('stops at midnight, whatever the closing time', function () {
        TenantSettings::for($this->shop)->set('day_close.closing_time', '23:30', $this->boss);

        // 00:10 on the 22nd in Riyadh is inside "two hours from 23:30", but it is the next day's evening that is asked for.
        expect(remindAt('2026-10-21 21:10:00'))->toBe(0);

        // 23:45 is still the day.
        expect(remindAt('2026-10-21 20:45:00'))->toBe(4);
    });

    it('gives a late closing time what is left of the evening, not a full two hours', function () {
        TenantSettings::for($this->shop)->set('day_close.closing_time', '22:30', $this->boss);

        // 22:30 plus two hours would be 00:30, but the window ends at midnight: ninety minutes.
        expect(remindAt('2026-10-21 21:01:00'))->toBe(0)
            ->and(remindAt('2026-10-21 20:59:00'))->toBe(4);
    });

    it('tells a person who works in two workspaces in each of them', function () {
        [$second, $secondOwner] = dayCloseOtherShop();
        $second->users()->attach($this->sara->id, ['role' => 'collector']);
        dayClosePhone($this->sara, $second, 'sara-second-phone');

        $this->travelTo('2026-10-21 17:00:00');
        runDayCloseReminders();

        expect($this->push->to('sara-phone'))->toHaveCount(1)
            ->and($this->push->to('sara-second-phone'))->toHaveCount(1)
            ->and($this->push->to('other-owner-phone-'.$second->id))->toHaveCount(1);
    });

    it('leaves demo and suspended workspaces alone', function () {
        dayCloseOtherShop(['is_demo' => true]);
        dayCloseOtherShop(['status' => 'suspended']);

        $this->travelTo('2026-10-21 17:00:00');
        runDayCloseReminders();

        // Only the shop of the test has phones that were told.
        expect(collect($this->push->sent)->pluck('token')->unique()->sort()->values()->all())->toBe(['abe-phone', 'boss-phone', 'mona-phone', 'sara-phone']);
    });

    it('a viewer is never reminded', function () {
        $told = remindAt('2026-10-21 17:00:00');

        expect($told)->toBe(4)
            ->and($this->push->to('vera-phone'))->toBe([])
            ->and(dayCloseInbox($this->vera, $this->shop, 'day_close_reminder'))->toBe([])
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('user_id', $this->vera->id)->count()))->toBe(0);
    });

    it('the push opens /day-close', function () {
        remindAt('2026-10-21 17:00:00');
        closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        // Both notices are visible pushes through the notifier, and each leaves its inbox entry with the same route.
        $reminder = $this->push->to('boss-phone')[0];
        $difference = dayClosePushes('day_close_difference')[0];
        expect($reminder->silent)->toBeFalse()
            ->and($reminder->data)->toBe(['route' => '/day-close'])
            ->and($difference->silent)->toBeFalse()
            ->and($difference->data)->toBe(['route' => '/day-close'])
            ->and(dayCloseInbox($this->boss, $this->shop, 'day_close_reminder')[0]->data)->toBe(['route' => '/day-close'])
            ->and(dayCloseInbox($this->boss, $this->shop, 'day_close_difference')[0]->data)->toBe(['route' => '/day-close']);
    });

    it('is written in the person’s own language and names no customer', function () {
        $this->sara->forceFill(['locale' => 'ar'])->save();
        remindAt('2026-10-21 17:00:00');

        $english = $this->push->to('boss-phone')[0];
        $arabic = $this->push->to('sara-phone')[0];
        expect($arabic->title)->toBe(InLanguage::run('ar', fn () => __('Time to close the day')))
            ->and($arabic->title)->not->toBe($english->title)
            ->and($arabic->body)->toBe(InLanguage::run('ar', fn () => __('Count the cash in the till and close today.')))
            ->and($english->title.$english->body.$arabic->title.$arabic->body)->not->toContain('Ahmad');
    });

    it('runs from the scheduler every five minutes, one run at a time', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'qistas:send-day-close-reminders'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('*/5 * * * *')
            ->and($events->first()->withoutOverlapping)->toBeTrue();
    });
});

describe('the notice of a difference', function () {
    it('a close with a difference by a collector notifies the owner once', function () {
        $this->travelTo('2026-10-21 17:20:00');
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        // Short by 10.00: only the owner is told, once, with the day, who closed and the signed amount.
        expect($this->push->to('boss-phone'))->toHaveCount(1)
            ->and($this->push->to('mona-phone'))->toBe([])
            ->and($this->push->to('sara-phone'))->toBe([])
            ->and($this->push->to('abe-phone'))->toBe([])
            ->and($this->push->to('vera-phone'))->toBe([]);
        $message = $this->push->to('boss-phone')[0];
        expect($message->type)->toBe('day_close_difference')
            ->and($message->title)->toBe(__('Cash difference at the close'))
            ->and(dayClosePlain($message->body))->toBe('Sara Collector closed 21 October 2026 with a cash difference of -SAR 10.00.')
            ->and($message->data)->toBe(['route' => '/day-close'])
            ->and(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toHaveCount(1)
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('type', 'day_close_difference')->pluck('dedupe_key')->all()))
            ->toBe(["day_close_difference:{$close->id}:{$this->boss->id}"]);

        // The same close is told once: asking again changes nothing.
        expect(app(DayCloseDifference::class)->tell($this->shop, $close, $this->sara))->toBe(0)
            ->and($this->push->to('boss-phone'))->toHaveCount(1);
    });

    it('tells every owner, each once, and says over with a plus sign', function () {
        $olga = closeStaff($this->shop, 'Olga Owner', 'owner');
        dayClosePhone($olga, $this->shop, 'olga-phone');

        $close = closeDay($this->shop, '2026-10-21', $this->mona, '105.50');

        expect($this->push->to('boss-phone'))->toHaveCount(1)
            ->and($this->push->to('olga-phone'))->toHaveCount(1)
            ->and(dayClosePlain($this->push->to('olga-phone')[0]->body))->toBe('Mona Manager closed 21 October 2026 with a cash difference of +SAR 5.50.')
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('type', 'day_close_difference')->count()))->toBe(2)
            ->and($close->status)->toBe('closed');
    });

    it('a balanced close notifies nobody', function () {
        closeDay($this->shop, '2026-10-21', $this->sara, '100.00');

        expect($this->push->sent)->toBe([])
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('type', 'day_close_difference')->count()))->toBe(0)
            ->and(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toBe([]);
    });

    it('does not tell the owners when an owner is the one who closed', function () {
        $olga = closeStaff($this->shop, 'Olga Owner', 'owner');
        dayClosePhone($olga, $this->shop, 'olga-phone');

        closeDay($this->shop, '2026-10-21', $this->boss, '90.00');

        expect($this->push->sent)->toBe([]);
    });

    it('respects an owner’s switch', function () {
        asTenant($this->shop, fn () => Preferences::update($this->boss->id, ['day_close_difference' => ['enabled' => false]]));

        $this->travelTo('2026-10-21 17:20:00');
        closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        expect($this->push->to('boss-phone'))->toBe([])
            ->and(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toBe([]);
    });

    it('keeps the notice in the inbox, without a push, for an owner in quiet hours', function () {
        $olga = closeStaff($this->shop, 'Olga Owner', 'owner');
        dayClosePhone($olga, $this->shop, 'olga-phone');
        asTenant($this->shop, fn () => Preferences::update($olga->id, ['quiet_hours' => ['enabled' => true, 'from' => '20:00', 'to' => '21:00']]));

        // 20:20 in Riyadh: Olga is in her quiet hours. Her phone stays quiet, but the fact is not hidden from her.
        $this->travelTo('2026-10-21 17:20:00');
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        $entries = dayCloseInbox($olga, $this->shop, 'day_close_difference');
        expect($this->push->to('olga-phone'))->toBe([])
            ->and($entries)->toHaveCount(1)
            ->and($entries[0]->data)->toBe(['route' => '/day-close'])
            ->and(dayClosePlain($entries[0]->body))->toBe('Sara Collector closed 21 October 2026 with a cash difference of -SAR 10.00.')
            // The key is recorded, so the same close can never put a second entry there.
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('user_id', $olga->id)->where('type', 'day_close_difference')->pluck('dedupe_key')->all()))
            ->toBe(["day_close_difference:{$close->id}:{$olga->id}"])
            ->and(app(DayCloseDifference::class)->tell($this->shop, $close, $this->sara))->toBe(0)
            ->and(dayCloseInbox($olga, $this->shop, 'day_close_difference'))->toHaveCount(1)
            ->and($this->push->to('olga-phone'))->toBe([]);
    });

    it('tells an owner who is not in quiet hours in the inbox and on the phone', function () {
        $olga = closeStaff($this->shop, 'Olga Owner', 'owner');
        $omar = closeStaff($this->shop, 'Omar Owner', 'owner');
        dayClosePhone($olga, $this->shop, 'olga-phone');
        dayClosePhone($omar, $this->shop, 'omar-phone');
        // At 20:20 Olga's quiet hours have not begun, and Omar's span ends at 20:00.
        asTenant($this->shop, function () use ($olga, $omar) {
            Preferences::update($olga->id, ['quiet_hours' => ['enabled' => true, 'from' => '22:00', 'to' => '08:00']]);
            Preferences::update($omar->id, ['quiet_hours' => ['enabled' => true, 'from' => '13:00', 'to' => '20:00']]);
        });

        $this->travelTo('2026-10-21 17:20:00');
        closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        foreach ([[$olga, 'olga-phone'], [$omar, 'omar-phone'], [$this->boss, 'boss-phone']] as [$owner, $phone]) {
            expect(dayCloseInbox($owner, $this->shop, 'day_close_difference'))->toHaveCount(1, $phone)
                ->and($this->push->to($phone))->toHaveCount(1, $phone);
        }
    });

    it('is written in the owner’s language, with the amount in the workspace currency', function () {
        $this->boss->forceFill(['locale' => 'ar'])->save();

        closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        $message = $this->push->to('boss-phone')[0];
        expect($message->title)->toBe(InLanguage::run('ar', fn () => __('Cash difference at the close')))
            ->and($message->title)->not->toBe('Cash difference at the close')
            ->and($message->body)->toContain('Sara Collector')
            ->and($message->body)->toContain(InLanguage::run('ar', fn () => Format::money('-10.00', 'SAR')));
    });

    it('names no customer and no one else’s money', function () {
        closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        $message = $this->push->to('boss-phone')[0];
        expect($message->title.$message->body)->not->toContain('Ahmad')
            ->and(json_encode($message->data))->not->toContain('Ahmad');
    });

    it('notifies nobody when the close is rolled back', function () {
        try {
            DB::transaction(function () {
                closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

                throw new RuntimeException('Something later in the same transaction failed.');
            });
        } catch (RuntimeException) {
            // Expected: the close and what it would have told are both gone.
        }

        expect(asTenant($this->shop, fn () => DayClose::query()->count()))->toBe(0)
            ->and($this->push->sent)->toBe([])
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->count()))->toBe(0)
            ->and(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toBe([]);
    });

    it('tells the owner only once the close is committed', function () {
        $told = null;
        DB::transaction(function () use (&$told) {
            closeDay($this->shop, '2026-10-21', $this->sara, '90.00');
            // Still inside the outer transaction: nothing has gone out.
            $told = count($this->push->sent);
        });

        expect($told)->toBe(0)
            ->and($this->push->to('boss-phone'))->toHaveCount(1);
    });

    it('never fails the close when the notice fails', function () {
        Log::spy();
        app()->instance(PushSender::class, new class implements PushSender
        {
            public function send(PushToken $token, PushMessage $message): bool
            {
                throw new RuntimeException('Firebase is down for Ahmad Ali.');
            }
        });

        $close = closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        expect($close->status)->toBe('closed')
            ->and(asTenant($this->shop, fn () => DayClose::query()->count()))->toBe(1);
        // It is told in the log, with nothing a person could be recognised by: no name, no message, no amount.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $line, array $context = []) => $line === 'day close difference notice failed'
            && array_keys($context) === ['close', 'error']
            && $context['close'] === $close->id
            && $context['error'] === RuntimeException::class)->once();
    });

    it('lets a close be written even when no one is left to tell', function () {
        // A workspace whose only member is a collector has no owner to tell.
        $solo = closeShop();
        $mara = closeStaff($solo, 'Mara Collector');
        $contract = closeContract($solo);
        closeTake($contract, '100.00', by: $mara);

        expect(closeDay($solo, '2026-10-21', $mara, '90.00')->status)->toBe('closed')
            ->and($this->push->sent)->toBe([]);
    });
});

describe('the two kinds', function () {
    it('are alerts that reach the app by default, switched on, with their own words', function () {
        foreach (['day_close_reminder', 'day_close_difference'] as $kind) {
            expect(Catalog::KINDS[$kind])->toBe(['group' => 'alerts', 'types' => [$kind], 'channels' => ['push' => true, 'email' => false, 'sms' => false]])
                ->and(Catalog::kindOf($kind))->toBe($kind)
                ->and(Preferences::DEFAULTS[$kind]['enabled'])->toBeTrue()
                ->and(Preferences::DEFAULTS[$kind]['channels'])->toBe(['push' => true, 'email' => false, 'sms' => false])
                ->and(Catalog::label($kind))->not->toBe($kind)->not->toBe('')
                ->and(Catalog::description($kind))->not->toBe('');
        }
    });

    it('are listed by the notification settings of the app, and can be switched off', function () {
        $data = asTenant($this->shop, fn () => Preferences::for($this->boss->id));
        expect($data['day_close_reminder']['enabled'])->toBeTrue()
            ->and($data['day_close_difference']['enabled'])->toBeTrue();

        Sanctum::actingAs($this->boss, ['app']);
        $response = $this->putJson('/api/v1/notifications/preferences', ['day_close_reminder' => ['enabled' => false]])
            ->assertOk()
            ->assertJsonPath('data.day_close_reminder.enabled', false)
            ->assertJsonPath('data.day_close_difference.enabled', true);
        $kinds = collect($response->json('meta.kinds'))->keyBy('key');
        expect($kinds['day_close_reminder']['group'])->toBe('alerts')
            ->and($kinds['day_close_difference']['group'])->toBe('alerts')
            ->and($kinds['day_close_reminder']['label'])->toBe(__('Evening reminder to close the day'));
    });

    it('are not offered by the app’s settings while the end-of-day close is off', function () {
        switchOff(Feature::DayClose);
        Sanctum::actingAs($this->boss, ['app']);

        $response = $this->getJson('/api/v1/notifications/preferences')->assertOk();

        $kinds = collect($response->json('meta.kinds'))->pluck('key');
        expect($kinds)->not->toContain('day_close_reminder')->not->toContain('day_close_difference')
            ->toContain('daily_digest')->toContain('support_reply')
            // The person's choices are kept as they are, for when it is switched on again.
            ->and($response->json('data.day_close_reminder.enabled'))->toBeTrue();
    });

    it('stay allowed in the app for an admin who saved the grid before they existed', function () {
        // The grid as it was saved by an earlier release: every kind then known, and not these two.
        $old = ['daily_digest' => ['push' => true], 'support_reply' => ['push' => true, 'email' => false, 'sms' => false]];
        (new PlatformSetting)->forceFill(['key' => Channels::KEY, 'value' => json_encode($old)])->save();

        $allowed = app(Channels::class)->allowed();

        // A kind the grid has never seen takes the catalogue's own default: in the app, not by e-mail or text message.
        expect($allowed['day_close_reminder'])->toBe(['push' => true, 'email' => false, 'sms' => false])
            ->and($allowed['day_close_difference'])->toBe(['push' => true, 'email' => false, 'sms' => false])
            // What the admin did decide is kept, including a channel left out of a kind they did save.
            ->and($allowed['daily_digest'])->toBe(['push' => true, 'email' => false, 'sms' => false]);

        // And the reminder reaches the phone: the grid does not silence it.
        expect(remindAt('2026-10-21 17:00:00'))->toBe(4)
            ->and($this->push->to('sara-phone'))->toHaveCount(1);
    });

    it('keep e-mail and text messages off when an admin saved a grid with them off everywhere', function () {
        // Every kind there was, in the app only.
        $old = [];
        foreach (['daily_digest', 'instalment_due', 'instalment_late', 'billing_renewal_due', 'billing_renewed', 'billing_payment_failed', 'billing_expired', 'support_reply'] as $kind) {
            $old[$kind] = ['push' => true, 'email' => false, 'sms' => false];
        }
        (new PlatformSetting)->forceFill(['key' => Channels::KEY, 'value' => json_encode($old)])->save();

        $allowed = app(Channels::class)->allowed();

        foreach ($allowed as $kind => $channels) {
            expect($channels['email'])->toBeFalse($kind)->and($channels['sms'])->toBeFalse($kind);
        }
        expect($allowed['day_close_reminder']['push'])->toBeTrue()
            ->and($allowed['day_close_difference']['push'])->toBeTrue();
    });

    it('stay blocked where the admin saved a row that blocks them', function () {
        $saved = ['day_close_reminder' => ['push' => false, 'email' => false, 'sms' => false], 'daily_digest' => ['push' => true, 'email' => false, 'sms' => false]];
        (new PlatformSetting)->forceFill(['key' => Channels::KEY, 'value' => json_encode($saved)])->save();

        expect(app(Channels::class)->allowed()['day_close_reminder'])->toBe(['push' => false, 'email' => false, 'sms' => false]);

        // The reminder reaches neither the phone nor the inbox; the notice of a difference, a kind the admin never saw, still does.
        remindAt('2026-10-21 17:00:00');
        expect($this->push->sent)->toBe([])
            ->and(dayCloseInbox($this->sara, $this->shop, 'day_close_reminder'))->toBe([]);

        closeDay($this->shop, '2026-10-21', $this->sara, '90.00');
        expect($this->push->to('boss-phone'))->toHaveCount(1);
    });
});

describe('telling without interrupting', function () {
    /** The workspace sends e-mail. */
    function dayCloseMailReady(): void
    {
        app(MailSettings::class)->save(['host' => 'smtp.example.test', 'port' => 587, 'security' => 'starttls', 'username' => 'u', 'password' => 'p', 'from_address' => 'noreply@qistas.test', 'from_name' => 'Qistas'], null);
    }

    /** The cover of a notice told once, under [$key]. */
    function dayCloseCover(string $key): array
    {
        return [['type' => 'day_close_difference', 'subject_type' => null, 'subject_id' => null, 'dedupe_key' => $key]];
    }

    it('keeps the inbox entry and the log line but sends no push, e-mail or text message', function () {
        Mail::fake();
        dayCloseMailReady();
        asTenant($this->shop, fn () => Preferences::update($this->boss->id, ['day_close_difference' => ['channels' => ['push' => true, 'email' => true]]]));
        $message = new PushMessage('day_close_difference', 'Title', 'Body', ['route' => '/day-close']);

        asTenant($this->shop, fn () => app(Notifier::class)->notify($this->boss, $message, dayCloseCover('quiet:1'), interrupt: false));

        $quiet = asTenant($this->shop, fn () => NotificationLogEntry::query()->where('dedupe_key', 'quiet:1')->first());
        expect(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toHaveCount(1)
            ->and($this->push->sent)->toBe([])
            ->and([$quiet->channel, $quiet->status])->toBe(['push', 'sent']);
        Mail::assertNothingQueued();

        // The default is unchanged: the same notice, with nothing said about interrupting, goes everywhere.
        asTenant($this->shop, fn () => app(Notifier::class)->notify($this->boss, $message, dayCloseCover('loud:1')));

        expect(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toHaveCount(2)
            ->and($this->push->to('boss-phone'))->toHaveCount(1)
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('dedupe_key', 'loud:1')->value('channel')))->toBe('push,email');
        Mail::assertQueued(NoticeMail::class, 1);
    });

    it('keeps nothing for a person who takes the kind by e-mail only', function () {
        Mail::fake();
        dayCloseMailReady();
        asTenant($this->shop, fn () => Preferences::update($this->boss->id, ['day_close_difference' => ['channels' => ['push' => false, 'email' => true]]]));
        $message = new PushMessage('day_close_difference', 'Title', 'Body', ['route' => '/day-close']);

        asTenant($this->shop, fn () => app(Notifier::class)->notify($this->boss, $message, dayCloseCover('quiet:2'), interrupt: false));

        // There is no inbox to put it in, and an e-mail is an interruption of its own.
        expect(dayCloseInbox($this->boss, $this->shop, 'day_close_difference'))->toBe([])
            ->and($this->push->sent)->toBe([])
            ->and(asTenant($this->shop, fn () => NotificationLogEntry::query()->where('dedupe_key', 'quiet:2')->value('status')))->toBe('skipped');
        Mail::assertNothingQueued();
    });
});

describe('the notification page on the web', function () {
    it('shows the notice of a difference to owners only, and the reminder only to people who may close', function () {
        $difference = 'When a day is closed with a cash difference';
        $reminder = 'Evening reminder to close the day';

        $this->actingAs($this->boss)->get(route('app.settings.notifications'))->assertOk()->assertSee($difference)->assertSee($reminder);
        foreach ([$this->mona, $this->sara, $this->abe] as $person) {
            $this->actingAs($person)->get(route('app.settings.notifications'))->assertOk()->assertDontSee($difference)->assertSee($reminder);
        }
        $this->actingAs($this->vera)->get(route('app.settings.notifications'))->assertOk()
            ->assertDontSee($difference)->assertDontSee($reminder)->assertSee('Support replies');
    });

    it('offers neither kind while the end-of-day close is off, to anyone', function (Closure $off) {
        $off();
        $difference = 'When a day is closed with a cash difference';
        $reminder = 'Evening reminder to close the day';

        foreach ([$this->boss, $this->mona, $this->abe, $this->sara, $this->vera] as $person) {
            $this->actingAs($person)->get(route('app.settings.notifications'))->assertOk()
                ->assertDontSee($difference)->assertDontSee($reminder)->assertSee('Support replies');
        }
        // The owner's own kinds are still there: only the switched-off feature's two have gone.
        $this->actingAs($this->boss)->get(route('app.settings.notifications'))->assertSee('Before your plan ends');
    })->with([
        'switched off for the platform' => [fn () => switchOff(Feature::DayClose)],
        'not in the plan' => [fn () => Plan::where('key', 'free')->sole()->setFeature(Feature::DayClose, false)],
    ]);

    it('saves nothing for either kind while the end-of-day close is off, and keeps the choice for when it is back', function () {
        asTenant($this->shop, fn () => Preferences::update($this->boss->id, ['day_close_difference' => ['enabled' => false]]));
        switchOff(Feature::DayClose);

        $this->actingAs($this->boss)->put(route('app.settings.notifications.update'), [
            'kinds' => ['day_close_difference' => ['enabled' => '1'], 'day_close_reminder' => ['enabled' => '0']],
        ])->assertRedirect();
        $this->actingAs($this->sara)->put(route('app.settings.notifications.update'), [
            'kinds' => ['day_close_reminder' => ['enabled' => '0']],
        ])->assertRedirect();

        expect(asTenant($this->shop, fn () => Preferences::for($this->boss->id)))
            ->day_close_difference->enabled->toBeFalse()
            ->day_close_reminder->enabled->toBeTrue()
            ->and(asTenant($this->shop, fn () => Preferences::for($this->sara->id)['day_close_reminder']['enabled']))->toBeTrue();

        switchOn(Feature::DayClose);
        $this->actingAs($this->boss)->get(route('app.settings.notifications'))->assertOk()->assertSee('When a day is closed with a cash difference');
    });

    it('saves nothing for a kind it did not show', function () {
        $this->actingAs($this->sara)->put(route('app.settings.notifications.update'), [
            'kinds' => ['day_close_difference' => ['enabled' => '0'], 'day_close_reminder' => ['enabled' => '1']],
        ])->assertRedirect();
        $this->actingAs($this->vera)->put(route('app.settings.notifications.update'), [
            'kinds' => ['day_close_reminder' => ['enabled' => '0']],
        ])->assertRedirect();

        // Left as they were: still on for the collector, and nothing changed for the viewer.
        expect(asTenant($this->shop, fn () => Preferences::for($this->sara->id)['day_close_difference']['enabled']))->toBeTrue()
            ->and(asTenant($this->shop, fn () => Preferences::for($this->vera->id)['day_close_reminder']['enabled']))->toBeTrue();
    });
});

describe('the reminder command', function () {
    it('carries on with the other workspaces when one of them fails', function () {
        Log::spy();
        [$broken] = dayCloseOtherShop();
        [$healthy] = dayCloseOtherShop();
        // The order the command takes the workspaces in: the one that fails is neither first nor last.
        expect(Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor()->pluck('id')->all())->toBe([$this->shop->id, $broken->id, $healthy->id]);

        // Firebase's side blows up for one workspace's phone only (a sender that throws, as a broken one might).
        app()->instance(PushSender::class, new class($this->push, $broken->id) implements PushSender
        {
            public function __construct(private readonly RecordingPushSender $inner, private readonly string $brokenTenant) {}

            public function send(PushToken $token, PushMessage $message): bool
            {
                if (str_ends_with($token->token, $this->brokenTenant)) {
                    throw new RuntimeException('Firebase refused the push for Ahmad Ali.');
                }

                return $this->inner->send($token, $message);
            }
        });

        $this->travelTo('2026-10-21 17:00:00');
        runDayCloseReminders();

        expect($this->push->to('sara-phone'))->toHaveCount(1)
            ->and($this->push->to('other-owner-phone-'.$healthy->id))->toHaveCount(1)
            ->and($this->push->to('other-owner-phone-'.$broken->id))->toBe([]);
        // The failure is in the log with the workspace and the kind of exception, and nothing a person could be recognised by.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $line, array $context = []) => $line === 'day close reminders failed'
            && $context === ['tenant' => $broken->id, 'error' => RuntimeException::class])->once();
    });
});
