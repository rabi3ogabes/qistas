<?php

use App\Models\Tenant;
use App\Support\WorkspaceDay;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
| A workspace's day is its own calendar day, not the server's: a payment at 23:50 in Riyadh belongs to that day even
| though it is already the next day in UTC. WorkspaceDay turns that local day into the two UTC instants that bound it
| (start inclusive, end exclusive), so that "today's money" is a plain range query. Daylight-saving days are 23 or 25
| hours long, and the day after a day is always the one that starts exactly where it ends.
*/

function dayWorkspace(string $country, ?string $timezone): Tenant
{
    return Tenant::factory()->create(['country' => $country, 'timezone' => $timezone]);
}

it('finds the local day a moment belongs to', function () {
    $riyadh = dayWorkspace('SA', 'Asia/Riyadh');

    $late = WorkspaceDay::of($riyadh, CarbonImmutable::parse('2026-10-21 20:30:00', 'UTC'));
    $next = WorkspaceDay::of($riyadh, CarbonImmutable::parse('2026-10-21 21:30:00', 'UTC'));

    expect($late->date)->toBe('2026-10-21')
        ->and($next->date)->toBe('2026-10-22')
        ->and($late->timezone)->toBe('Asia/Riyadh')
        ->and($late->start->format('Y-m-d H:i'))->toBe('2026-10-20 21:00')
        ->and($late->end->format('Y-m-d H:i'))->toBe('2026-10-21 21:00')
        ->and($late->start->getTimezone()->getName())->toBe('UTC')
        ->and($late->end->getTimezone()->getName())->toBe('UTC');
});

it('spans 23 hours when the clocks go forward and 25 when they go back', function () {
    $newYork = dayWorkspace('US', 'America/New_York');

    $forward = WorkspaceDay::on($newYork, '2026-03-08');
    $back = WorkspaceDay::on($newYork, '2026-11-01');
    $plain = WorkspaceDay::on($newYork, '2026-06-15');

    expect((int) $forward->start->diffInHours($forward->end))->toBe(23)
        ->and((int) $back->start->diffInHours($back->end))->toBe(25)
        ->and((int) $plain->start->diffInHours($plain->end))->toBe(24);
});

it('treats the end as exclusive', function () {
    $day = WorkspaceDay::on(dayWorkspace('SA', 'Asia/Riyadh'), '2026-10-21');

    expect($day->contains($day->start))->toBeTrue()
        ->and($day->contains($day->end))->toBeFalse()
        ->and($day->contains($day->end->subSecond()))->toBeTrue()
        ->and($day->contains($day->start->subSecond()))->toBeFalse();
});

it('steps back across a month boundary', function () {
    $day = WorkspaceDay::on(dayWorkspace('SA', 'Asia/Riyadh'), '2026-11-01');

    $previous = $day->previous();

    expect($previous->date)->toBe('2026-10-31')
        ->and($previous->timezone)->toBe('Asia/Riyadh')
        ->and($previous->end->equalTo($day->start))->toBeTrue();
});

it('turns a wall-clock time into an instant', function () {
    $day = WorkspaceDay::on(dayWorkspace('SA', 'Asia/Riyadh'), '2026-10-21');

    $eight = $day->atTime('20:00');

    expect($eight->format('Y-m-d H:i'))->toBe('2026-10-21 17:00')
        ->and($eight->getTimezone()->getName())->toBe('UTC')
        ->and($day->contains($eight))->toBeTrue();
});

it('follows the country’s zone when the workspace has none stored', function () {
    $tenant = dayWorkspace('SA', null);

    $day = WorkspaceDay::of($tenant, CarbonImmutable::parse('2026-10-21 21:30:00', 'UTC'));

    expect($tenant->fresh()->timezone)->toBeNull()
        ->and($day->timezone)->toBe('Asia/Riyadh')
        ->and($day->date)->toBe('2026-10-22');
});

it('keeps the days joined where a zone skips its own midnight', function () {
    // Egypt moves its clocks forward at midnight, so 00:00 never happens on 24 Apr 2026: the day starts at 01:00.
    $cairo = dayWorkspace('EG', 'Africa/Cairo');

    $day = WorkspaceDay::on($cairo, '2026-04-24');

    expect((int) $day->start->diffInHours($day->end))->toBe(23)
        ->and($day->previous()->end->equalTo($day->start))->toBeTrue()
        ->and(WorkspaceDay::on($cairo, '2026-04-25')->previous()->date)->toBe('2026-04-24');
});

it('does not change the moment it is given', function () {
    $moment = Carbon::parse('2026-10-21 21:30:00', 'UTC');

    WorkspaceDay::of(dayWorkspace('SA', 'Asia/Riyadh'), $moment);

    expect($moment->getTimezone()->getName())->toBe('UTC')
        ->and($moment->format('H:i'))->toBe('21:30');
});

it('uses the current moment when none is given', function () {
    $this->travelTo('2026-10-21 21:30:00');

    expect(WorkspaceDay::of(dayWorkspace('SA', 'Asia/Riyadh'))->date)->toBe('2026-10-22');
});
