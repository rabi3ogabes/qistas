<?php

declare(strict_types=1);

namespace App\Theme;

use InvalidArgumentException;

/**
 * Colour maths for the theme engine: a line-for-line port of the helpers in brand/shared/qistas-theme.js, because the
 * website, the Android app and this server must agree on every derived colour. Colours are '#RRGGBB' strings.
 * Where the reference rounds with Math.round (half up) so does this (floor of x + .5), so the two never differ by a digit.
 */
final class Color
{
    /** A colour a person may pin: '#' and six hex digits, nothing else. */
    public static function isHex(string $value): bool
    {
        return preg_match('/\A#[0-9A-Fa-f]{6}\z/', $value) === 1;
    }

    /** The colour in capitals, or an exception: this is the one gate between input and a stylesheet. */
    public static function normalise(string $value): string
    {
        if (! self::isHex($value)) {
            throw new InvalidArgumentException('A colour must be written as #RRGGBB.');
        }

        return strtoupper($value);
    }

    /** @return array{int, int, int} */
    public static function hexToRgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        $n = (int) hexdec($h);

        return [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];
    }

    public static function rgbToHex(float $r, float $g, float $b): string
    {
        $part = fn (float $v): string => str_pad(strtoupper(dechex((int) self::clamp(floor($v + 0.5), 0, 255))), 2, '0', STR_PAD_LEFT);

        return '#'.$part($r).$part($g).$part($b);
    }

    /** @return array{float, float, float} hue in degrees, saturation and lightness in 0 to 1 */
    public static function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = 0.0;
        $s = 0.0;
        $l = ($max + $min) / 2;

        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            if ($max === $r) {
                $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
            } elseif ($max === $g) {
                $h = ($b - $r) / $d + 2;
            } else {
                $h = ($r - $g) / $d + 4;
            }

            $h *= 60;
        }

        return [$h, $s, $l];
    }

    public static function hslToHex(float $h, float $s, float $l): string
    {
        $s = self::clamp($s, 0, 1);
        $l = self::clamp($l, 0, 1);
        $h = fmod(fmod($h, 360) + 360, 360);
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return self::rgbToHex(($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255);
    }

    public static function mix(string $a, string $b, float $t): string
    {
        $A = self::hexToRgb($a);
        $B = self::hexToRgb($b);

        return self::rgbToHex($A[0] + ($B[0] - $A[0]) * $t, $A[1] + ($B[1] - $A[1]) * $t, $A[2] + ($B[2] - $A[2]) * $t);
    }

    public static function luminance(string $hex): float
    {
        $f = function (float $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::hexToRgb($hex);

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** WCAG contrast ratio, 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function lighten(string $hex, float $amount): string
    {
        [$h, $s, $l] = self::hexToHsl($hex);

        return self::hslToHex($h, $s, $l + $amount);
    }

    public static function darken(string $hex, float $amount): string
    {
        return self::lighten($hex, -$amount);
    }

    /** The more readable of two text colours on a background; pure black or white when neither reaches 4.5. */
    public static function readableOn(string $bg, string $light = '#F7F3EA', string $dark = '#0B1F44'): string
    {
        $best = self::contrast($light, $bg) >= self::contrast($dark, $bg) ? $light : $dark;

        if (self::contrast($best, $bg) >= 4.5) {
            return $best;
        }

        return self::contrast('#FFFFFF', $bg) >= self::contrast('#000000', $bg) ? '#FFFFFF' : '#000000';
    }

    /**
     * A text colour that stays readable on every one of several backgrounds (the stops of a gradient).
     *
     * @param  list<string>  $bgs
     */
    public static function readableOnAll(array $bgs, string $light = '#F7F3EA', string $dark = '#0B1F44'): string
    {
        $worst = fn (string $c): float => min(array_map(fn (string $bg): float => self::contrast($c, $bg), $bgs));
        $best = $worst($light) >= $worst($dark) ? $light : $dark;

        if ($worst($best) >= 4.5) {
            return $best;
        }

        return $worst('#FFFFFF') >= $worst('#000000') ? '#FFFFFF' : '#000000';
    }

    public static function darkerOf(string $a, string $b): string
    {
        return self::luminance($a) <= self::luminance($b) ? $a : $b;
    }

    public static function lighterOf(string $a, string $b): string
    {
        return self::luminance($a) >= self::luminance($b) ? $a : $b;
    }

    /** Moves [$fg] away from [$bg] in lightness until it reaches [$min] contrast (black or white at the very end). */
    public static function ensureContrast(string $fg, string $bg, float $min = 4.5): string
    {
        if (self::contrast($fg, $bg) >= $min) {
            return strtoupper($fg);
        }

        [$h, $s, $l] = self::hexToHsl($fg);
        $direction = self::luminance($bg) > 0.4 ? -1 : 1;

        for ($i = 1; $i <= 100; $i++) {
            $c = self::hslToHex($h, $s, self::clamp($l + $direction * $i * 0.01, 0, 1));

            if (self::contrast($c, $bg) >= $min) {
                return $c;
            }
        }

        return $direction < 0 ? '#000000' : '#FFFFFF';
    }

    public static function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }
}
