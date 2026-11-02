<?php

use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

/** A sign-in row that is $days old (and a few hours more, so "89 days" is not a boundary case by accident). */
function pruneSignInsRow(User $user, int $days, int $hours = 0, string $outcome = 'success'): SignInEvent
{
    return SignInEvent::query()->forceCreate([
        'user_id' => $user->id, 'method' => 'password', 'channel' => 'web', 'outcome' => $outcome,
        'device_type' => 'desktop', 'ip' => '203.0.113.9', 'proxy' => 'not_detected', 'proxy_signals' => [],
        'created_at' => now()->subDays($days)->subHours($hours),
    ]);
}

beforeEach(function () {
    $this->travelTo('2026-11-02 12:00:00');
    config(['qistas.sign_in_days' => 90]);

    $this->user = User::factory()->create();
});

it('removes rows older than 90 days and keeps the ones inside the window', function () {
    $gone = pruneSignInsRow($this->user, 91);
    $alsoGone = pruneSignInsRow($this->user, 400, outcome: 'failed');
    $stays = pruneSignInsRow($this->user, 89);
    $fresh = pruneSignInsRow($this->user, 0);

    $this->artisan('qistas:sign-ins:prune')->assertSuccessful();

    expect(SignInEvent::query()->pluck('id')->sort()->values()->all())->toBe(collect([$stays->id, $fresh->id])->sort()->values()->all())
        ->and(SignInEvent::query()->whereKey([$gone->id, $alsoGone->id])->count())->toBe(0);
});

it('keeps a row that is exactly at the edge of the window', function () {
    $edge = pruneSignInsRow($this->user, 90);
    $justOutside = pruneSignInsRow($this->user, 90, hours: 1);

    $this->artisan('qistas:sign-ins:prune')->assertSuccessful();

    expect(SignInEvent::query()->whereKey($edge->id)->exists())->toBeTrue()
        ->and(SignInEvent::query()->whereKey($justOutside->id)->exists())->toBeFalse();
});

it('says how many it removed', function () {
    pruneSignInsRow($this->user, 120);
    pruneSignInsRow($this->user, 100);
    pruneSignInsRow($this->user, 3);

    $this->artisan('qistas:sign-ins:prune')->expectsOutputToContain('Removed 2 sign-in records older than 90 days.')->assertSuccessful();

    pruneSignInsRow($this->user, 200);

    $this->artisan('qistas:sign-ins:prune')->expectsOutputToContain('Removed 1 sign-in record older than 90 days.')->assertSuccessful();

    $this->artisan('qistas:sign-ins:prune')->expectsOutputToContain('Removed 0 sign-in records older than 90 days.')->assertSuccessful();
});

it('honours the days setting, read when it runs', function () {
    $tenDaysOld = pruneSignInsRow($this->user, 10);
    $fortyDaysOld = pruneSignInsRow($this->user, 40);
    $eightyDaysOld = pruneSignInsRow($this->user, 80);

    config(['qistas.sign_in_days' => 30]);
    $this->artisan('qistas:sign-ins:prune')->expectsOutputToContain('older than 30 days')->assertSuccessful();

    expect(SignInEvent::query()->pluck('id')->all())->toBe([$tenDaysOld->id]);

    // The same command, the same process, a longer window now: nothing more goes, and nothing is remembered from before.
    config(['qistas.sign_in_days' => 365]);
    $this->artisan('qistas:sign-ins:prune')->assertSuccessful();

    expect(SignInEvent::query()->count())->toBe(1)
        ->and(SignInEvent::query()->whereKey([$fortyDaysOld->id, $eightyDaysOld->id])->count())->toBe(0);
});

it('removes rows of every person, not only the one in front of it', function () {
    $other = User::factory()->create();

    pruneSignInsRow($this->user, 100);
    pruneSignInsRow($other, 100);
    $stays = pruneSignInsRow($other, 5);

    $this->artisan('qistas:sign-ins:prune')->assertSuccessful();

    expect(SignInEvent::query()->pluck('id')->all())->toBe([$stays->id]);
});

it('deletes nothing and fails when the days setting is not a positive number', function (mixed $days) {
    pruneSignInsRow($this->user, 500);
    pruneSignInsRow($this->user, 1);

    config(['qistas.sign_in_days' => $days]);

    $this->artisan('qistas:sign-ins:prune')->assertFailed();

    expect(SignInEvent::query()->count())->toBe(2);
})->with(['zero' => 0, 'negative' => -5, 'empty' => '', 'text' => 'forever', 'null' => null]);

it('is the one thing that removes rows, although the model refuses to delete them', function () {
    $event = pruneSignInsRow($this->user, 120);

    expect(fn () => $event->delete())->toThrow(LogicException::class);

    $this->artisan('qistas:sign-ins:prune')->assertSuccessful();

    expect(SignInEvent::query()->count())->toBe(0);
});

it('runs from the scheduler every day, one run at a time', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'qistas:sign-ins:prune'));

    expect($events)->toHaveCount(1);

    $event = $events->sole();

    expect($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);
});
