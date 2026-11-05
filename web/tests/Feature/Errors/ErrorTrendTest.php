<?php

use App\Errors\ErrorTrend;
use App\Models\ErrorIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| ErrorTrend is the one place that reads an issue's daily counts and decides "growing" (design sections 7 and 8): the
| admin list draws its sparklines and marks from it, and the daily digest will use the same two helpers. The rule lives
| here only, so these tests pin it.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:30:00', 'UTC'));
});

/** An issue with the daily counts the recorder would have written: ['2026-11-04' => 3, ...]. */
function errorsTrendIssue(array $days = []): ErrorIssue
{
    $issue = ErrorIssue::query()->forceCreate([
        'fingerprint' => sha1(Str::random(32)),
        'source' => 'site',
        'kind' => 'exception',
        'title' => 'RuntimeException: something broke',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    foreach ($days as $day => $count) {
        DB::table('error_issue_days')->insert(['issue_id' => $issue->id, 'day' => $day, 'count' => $count]);
    }

    return $issue;
}

describe('days', function () {
    it('gives the last seven daily counts, oldest first, with the quiet days as zero', function () {
        $issue = errorsTrendIssue(['2026-11-04' => 5, '2026-11-02' => 2, '2026-10-29' => 1]);

        expect(ErrorTrend::days([$issue->id]))->toBe([$issue->id => [1, 0, 0, 0, 2, 0, 5]]);
    });

    it('leaves out a day older than the window and a day that has not happened yet', function () {
        $issue = errorsTrendIssue(['2026-10-28' => 9, '2026-11-05' => 9, '2026-11-04' => 1]);

        expect(ErrorTrend::days([$issue->id])[$issue->id])->toBe([0, 0, 0, 0, 0, 0, 1]);
    });

    it('answers for an issue that has no days at all, and for the number of days asked', function () {
        $issue = errorsTrendIssue();

        expect(ErrorTrend::days([$issue->id], 30)[$issue->id])->toBe(array_fill(0, 30, 0))
            ->and(ErrorTrend::days([$issue->id], 8)[$issue->id])->toHaveCount(8);
    });

    it('keeps each issue to its own days', function () {
        $first = errorsTrendIssue(['2026-11-04' => 3]);
        $second = errorsTrendIssue(['2026-11-03' => 4]);

        expect(ErrorTrend::days([$first->id, $second->id]))->toBe([
            $first->id => [0, 0, 0, 0, 0, 0, 3],
            $second->id => [0, 0, 0, 0, 0, 4, 0],
        ]);
    });

    it('reads every issue of a page with one query', function () {
        $ids = collect(range(1, 25))->map(fn () => errorsTrendIssue(['2026-11-04' => 1, '2026-11-01' => 2])->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $series = ErrorTrend::days($ids);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)->and($series)->toHaveCount(25)->and($series[$ids[0]])->toBe([0, 0, 0, 2, 0, 0, 1]);
    });

    it('accepts any iterable of ids, asks nothing of the database for none, and counts a repeated id once', function () {
        $issue = errorsTrendIssue(['2026-11-04' => 2]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        expect(ErrorTrend::days([]))->toBe([]);
        $none = DB::getQueryLog();
        DB::disableQueryLog();

        $generator = (function () use ($issue) {
            yield $issue->id;
            yield $issue->id;
        })();

        expect($none)->toBe([])->and(ErrorTrend::days($generator))->toBe([$issue->id => [0, 0, 0, 0, 0, 0, 2]]);
    });

    it('reads the days up to the moment it is given, on the application clock, not up to now', function () {
        config(['app.timezone' => 'Asia/Riyadh']);
        $issue = errorsTrendIssue(['2026-11-04' => 9, '2026-11-01' => 3, '2026-10-31' => 2, '2026-10-29' => 1]);

        // 23:00 on 31 October in UTC is 02:00 on 1 November in Riyadh: that is the last day of the window.
        expect(ErrorTrend::days([$issue->id], 4, CarbonImmutable::parse('2026-10-31 23:00:00', 'UTC'))[$issue->id])->toBe([1, 0, 2, 3])
            ->and(ErrorTrend::days([$issue->id], 4)[$issue->id])->toBe([3, 0, 0, 9]);
    });

    it('counts a day on the application clock, as the recorder writes it', function () {
        config(['app.timezone' => 'Asia/Riyadh']);
        // 01:30 on 5 November in Riyadh, still 4 November in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 22:30:00', 'UTC'));
        $issue = errorsTrendIssue(['2026-11-05' => 4, '2026-11-04' => 1]);

        expect(ErrorTrend::days([$issue->id], 2)[$issue->id])->toBe([1, 4]);
    });
});

describe('growing', function () {
    it('needs at least three today and at least twice the average of the seven days before', function (int $today, array $before, bool $growing) {
        expect(ErrorTrend::growing($today, $before))->toBe($growing);
    })->with([
        'quiet week, three today' => [3, [0, 0, 0, 0, 0, 0, 0], true],
        'two today is never enough' => [2, [0, 0, 0, 0, 0, 0, 0], false],
        'nothing today' => [0, [0, 0, 0, 0, 0, 0, 0], false],
        'three against an average of one' => [3, [1, 1, 1, 1, 1, 1, 1], true],
        'three against an average of two' => [3, [2, 2, 2, 2, 2, 2, 2], false],
        'exactly double the average' => [4, [2, 2, 2, 2, 2, 2, 2], true],
        'same as every day' => [5, [5, 5, 5, 5, 5, 5, 5], false],
        'one bad day in the week' => [6, [3, 0, 0, 0, 0, 0, 0], true],
        'just over double (sum 10)' => [3, [2, 2, 2, 2, 2, 0, 0], true],
        'just under double (sum 11)' => [3, [2, 2, 2, 2, 2, 1, 0], false],
        'a few days missing count as zero' => [3, [1, 1], true],
        'only the last seven days count' => [4, [9, 9, 1, 1, 1, 1, 1, 1, 1], true],
    ]);

    it('is fed by days(), eight days at a time: the seven before and today', function () {
        $growing = errorsTrendIssue(['2026-11-04' => 6, '2026-11-02' => 1]);
        $steady = errorsTrendIssue(['2026-11-04' => 6, '2026-11-03' => 5, '2026-11-02' => 5, '2026-11-01' => 5, '2026-10-31' => 5, '2026-10-30' => 5, '2026-10-29' => 5, '2026-10-28' => 5]);

        $series = ErrorTrend::days([$growing->id, $steady->id], 8);
        $check = fn (string $id): bool => ErrorTrend::growing(...ErrorTrend::split($series[$id]));

        expect($check($growing->id))->toBeTrue()->and($check($steady->id))->toBeFalse();
    });

    it('splits eight days into today and the seven before it', function (array $days, int $today, array $before) {
        expect(ErrorTrend::split($days))->toBe([$today, $before]);
    })->with([
        'eight days' => [[1, 2, 3, 4, 5, 6, 7, 8], 8, [1, 2, 3, 4, 5, 6, 7]],
        'more than eight: only the seven before today' => [[9, 9, 1, 2, 3, 4, 5, 6, 7, 8], 8, [1, 2, 3, 4, 5, 6, 7]],
        'fewer than eight' => [[4, 5], 5, [4]],
        'one day' => [[3], 3, []],
        'none' => [[], 0, []],
    ]);
});

describe('noise', function () {
    it('gives the last seven days of scanner and dropped counts, newest first, with the quiet days as zero', function () {
        DB::table('error_noise_days')->insert([
            ['day' => '2026-11-04', 'kind' => 'scanner', 'count' => 40],
            ['day' => '2026-11-04', 'kind' => 'dropped', 'count' => 3],
            ['day' => '2026-11-02', 'kind' => 'scanner', 'count' => 7],
            ['day' => '2026-10-28', 'kind' => 'scanner', 'count' => 99],
        ]);

        $noise = ErrorTrend::noise();

        expect($noise)->toHaveCount(7)
            ->and($noise[0])->toBe(['day' => '2026-11-04', 'scanner' => 40, 'dropped' => 3])
            ->and($noise[1])->toBe(['day' => '2026-11-03', 'scanner' => 0, 'dropped' => 0])
            ->and($noise[2])->toBe(['day' => '2026-11-02', 'scanner' => 7, 'dropped' => 0])
            ->and($noise[6]['day'])->toBe('2026-10-29')
            ->and(collect($noise)->sum('scanner'))->toBe(47);
    });
});
