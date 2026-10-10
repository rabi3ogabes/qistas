<?php

namespace App\Domain\Schedule;

/** How each way of paying is said to people, in the order a form offers it. One list for every page. */
final class Frequencies
{
    /**
     * Every rhythm and the shop's own dates; or, without flexible schedules, the basic three as they were always shown.
     *
     * @return array<string, string>
     */
    public static function labels(bool $flexible = true): array
    {
        if (! $flexible) {
            return ['monthly' => __('Every month'), 'biweekly' => __('Every two weeks'), 'weekly' => __('Every week')];
        }

        return [
            'daily' => __('Every day'),
            'weekly' => __('Every week'),
            'biweekly' => __('Every two weeks'),
            'monthly' => __('Every month'),
            'bimonthly' => __('Every two months'),
            'quarterly' => __('Every three months'),
            'semiannual' => __('Every six months'),
            'yearly' => __('Every year'),
            ScheduleGenerator::CUSTOM => __('On dates I choose'),
        ];
    }

    public static function label(string $frequency): string
    {
        return self::labels()[$frequency] ?? $frequency;
    }
}
