<?php

declare(strict_types=1);

namespace App\Theme;

use App\Models\AppearanceEvent;
use App\Support\Countries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The events as the Appearance page shows them: where each stands in words (On now, Starts in 3 days, Ended), the
 * events it meets and which of them people see where, and its place on a twelve-month timeline.
 */
final class EventCalendar
{
    /** The timeline's width in its own units (an SVG viewBox), and how many months it shows. */
    public const WIDTH = 1000;

    public const MONTHS = 12;

    /**
     * @param  Collection<int, AppearanceEvent>  $events
     * @return list<array{event: AppearanceEvent, state: string, label: string, countries: list<string>, overlaps: list<string>}>
     */
    public static function rows(Collection $events, CarbonInterface $now): array
    {
        $names = Countries::options();
        $rows = [];

        foreach ($events as $event) {
            $state = $event->stateAt($now);
            $rows[] = [
                'event' => $event,
                'state' => $state,
                'label' => self::label($event, $state, $now),
                'countries' => array_map(fn (string $code): string => $names[$code] ?? $code, $event->countries ?? []),
                'overlaps' => in_array($state, ['scheduled', 'live'], true) ? self::overlaps($event, $events, $now, $names) : [],
            ];
        }

        return $rows;
    }

    /**
     * The timeline: the months from the first of this month, and a bar for each event that is not over, in units of
     * the timeline's width.
     *
     * @param  Collection<int, AppearanceEvent>  $events
     * @return array{months: list<array{x: float, label: string}>, today: float, bars: list<array{id: string, x: float, width: float, colour: string, draft: bool, name: string}>}
     */
    public static function timeline(Collection $events, CarbonInterface $now): array
    {
        $start = CarbonImmutable::instance($now)->startOfMonth()->startOfDay();
        $end = $start->addMonths(self::MONTHS);
        $span = (float) $start->diffInDays($end);
        $at = fn (CarbonInterface $day): float => round(max(0, min($span, $start->diffInDays($day, false))) / $span * self::WIDTH, 2);

        $months = [];
        for ($month = $start; $month->lessThan($end); $month = $month->addMonth()) {
            $months[] = ['x' => $at($month), 'label' => $month->translatedFormat('M')];
        }

        $bars = [];
        foreach ($events as $event) {
            if ($event->stateAt($now) === 'ended' || $event->ends_on->lessThan($start) || $event->starts_on->greaterThanOrEqualTo($end)) {
                continue;
            }

            $x = $at($event->starts_on);
            $bars[] = [
                'id' => $event->id,
                'x' => $x,
                // A day is a sliver on a year: never thinner than can be seen and pointed at.
                'width' => max(6.0, $at($event->ends_on->addDay()) - $x),
                'colour' => $event->pins()['light']['primary'] ?? ThemeEngine::BASE['light']['primary'],
                'draft' => $event->status !== 'scheduled',
                'name' => $event->name,
            ];
        }

        return ['months' => $months, 'today' => $at(CarbonImmutable::instance($now)->startOfDay()), 'bars' => $bars];
    }

    private static function label(AppearanceEvent $event, string $state, CarbonInterface $now): string
    {
        if ($state !== 'scheduled') {
            return match ($state) {
                'draft' => __('Draft'),
                'live' => __('On now'),
                default => __('Ended'),
            };
        }

        $days = (int) CarbonImmutable::parse($event->localDate($now))->diffInDays($event->starts_on);

        return $days <= 1 ? __('Starts tomorrow') : __('Starts in :days days', ['days' => $days]);
    }

    /**
     * What [$event] meets: each scheduled event whose days, places and countries touch its own, with who wins where.
     *
     * @param  Collection<int, AppearanceEvent>  $events
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private static function overlaps(AppearanceEvent $event, Collection $events, CarbonInterface $now, array $names): array
    {
        $notes = [];

        foreach ($events as $other) {
            if ($other->is($event) || ! in_array($other->stateAt($now), ['scheduled', 'live'], true)) {
                continue;
            }

            $daysMeet = $event->starts_on->lessThanOrEqualTo($other->ends_on) && $other->starts_on->lessThanOrEqualTo($event->ends_on);
            $placesMeet = array_intersect($event->surfaces ?? [], $other->surfaces ?? []) !== [];
            $mine = $event->countries ?? [];
            $theirs = $other->countries ?? [];
            $shared = match (true) {
                $mine === [] && $theirs === [] => null,
                $mine === [] => $theirs,
                $theirs === [] => $mine,
                default => array_values(array_intersect($mine, $theirs)),
            };

            if (! $daysMeet || ! $placesMeet || $shared === []) {
                continue;
            }

            $winner = $event->outranks($other) ? $event : $other;
            $where = $shared === null
                ? __('Everywhere, people see “:event”.', ['event' => $winner->name])
                : __('In :country, people see “:event”.', ['country' => implode(', ', array_map(fn (string $c): string => $names[$c] ?? $c, $shared)), 'event' => $winner->name]);

            $notes[] = __('Overlaps with “:other”.', ['other' => $other->name]).' '.$where;
        }

        return $notes;
    }
}
