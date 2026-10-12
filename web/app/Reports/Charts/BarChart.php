<?php

namespace App\Reports\Charts;

use App\Support\Format;
use App\Support\Money;

/**
 * Bar charts drawn by the server as inline SVG (Win Plan K8, decision D7): no chart library, no script, colours from
 * the theme's own tokens (so dark mode and the admin's look apply), and every bar names its label and amount for a
 * screen reader and on hover. The table beside each chart carries the same figures.
 */
final class BarChart
{
    private const WIDTH = 640;

    /**
     * Columns from left to right (months, weeks).
     *
     * @param  list<array{label: string, value: string}>  $bars
     */
    public static function columns(array $bars, string $title, string $currency, string $tone = 'accent'): string
    {
        $height = 220;
        $top = 16;
        $bottom = 30;
        $plot = $height - $top - $bottom;
        $max = self::max($bars);
        $count = max(count($bars), 1);
        $slot = self::WIDTH / $count;
        $bar = min(44, $slot * 0.62);
        // Labels thin out when there are many bars, so they never run into each other.
        $every = $count > 8 ? 2 : 1;

        $shapes = '';
        foreach ($bars as $i => $item) {
            $value = Money::isPositive($item['value']) ? $item['value'] : '0';
            $h = Money::isZero($max) ? 0.0 : max(Money::isPositive($value) ? 2.0 : 0.0, (float) Money::div(Money::mul($value, (string) $plot, 6), $max, 6));
            $x = $i * $slot + ($slot - $bar) / 2;
            $y = $top + $plot - $h;
            $shapes .= sprintf('<rect class="chart-bar chart-%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3"><title>%s</title></rect>',
                e($tone), $x, $y, $bar, $h, e($item['label'].': '.Format::money($item['value'], $currency)));
            if ($i % $every === 0) {
                $shapes .= sprintf('<text class="chart-label" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x + $bar / 2, $height - 9, e($item['label']));
            }
        }
        $shapes .= sprintf('<line class="chart-axis" x1="0" y1="%d" x2="%d" y2="%d" />', $top + $plot, self::WIDTH, $top + $plot);

        return self::svg($title, self::WIDTH, $height, $shapes);
    }

    /**
     * Rows from top to bottom (ageing buckets), each with its amount at its end.
     *
     * @param  list<array{label: string, value: string, tone?: string}>  $bars
     */
    public static function rows(array $bars, string $title, string $currency): string
    {
        $row = 38;
        $height = count($bars) * $row + 4;
        // Room for the labels and amounts at the larger size a phone draws them at.
        $labelWidth = 215;
        $amountWidth = 180;
        $plot = self::WIDTH - $labelWidth - $amountWidth;
        $max = self::max($bars);

        $shapes = '';
        foreach ($bars as $i => $item) {
            $y = $i * $row + 6;
            $value = Money::isPositive($item['value']) ? $item['value'] : '0';
            $w = Money::isZero($max) ? 0.0 : max(Money::isPositive($value) ? 3.0 : 0.0, (float) Money::div(Money::mul($value, (string) $plot, 6), $max, 6));
            $shapes .= sprintf('<text class="chart-label chart-label-row" x="0" y="%.1f">%s</text>', $y + 17, e($item['label']));
            $shapes .= sprintf('<rect class="chart-track" x="%d" y="%d" width="%d" height="22" rx="4" />', $labelWidth, $y + 2, $plot);
            $shapes .= sprintf('<rect class="chart-bar chart-%s" x="%d" y="%d" width="%.1f" height="22" rx="4"><title>%s</title></rect>',
                e($item['tone'] ?? 'accent'), $labelWidth, $y + 2, $w, e($item['label'].': '.Format::money($item['value'], $currency)));
            $shapes .= sprintf('<text class="chart-value" x="%d" y="%.1f" text-anchor="end">%s</text>', self::WIDTH, $y + 17, e(Format::money($item['value'], $currency)));
        }

        return self::svg($title, self::WIDTH, $height, $shapes);
    }

    /** @param  list<array{label: string, value: string}>  $bars */
    private static function max(array $bars): string
    {
        return array_reduce($bars, fn (string $max, array $bar) => Money::cmp($bar['value'], $max) > 0 ? $bar['value'] : $max, '0');
    }

    private static function svg(string $title, int $width, int $height, string $shapes): string
    {
        $id = 'chart-'.substr(md5($title.$shapes), 0, 10);

        return sprintf('<svg class="chart" viewBox="0 0 %d %d" role="img" aria-labelledby="%s" dir="ltr" preserveAspectRatio="xMidYMid meet"><title id="%s">%s</title>%s</svg>',
            $width, $height, $id, $id, e($title), $shapes);
    }
}
