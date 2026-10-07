<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal money arithmetic on strings (bcmath). The application never uses floats for money.
 * Amounts are stored as decimal(18,4); everything is normalised to scale 4.
 */
final class Money
{
    public const SCALE = 4;

    /** Validate untrusted input and normalise it to scale 4. */
    public static function parse(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^-?\d+(\.\d{1,4})?$/', $value)) {
            throw new InvalidArgumentException('A money amount must be a plain decimal string with at most 4 decimals.');
        }

        return bcadd($value, '0', self::SCALE);
    }

    public static function add(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcadd($a, $b, $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcsub($a, $b, $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcmul($a, $b, $scale);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        return bcdiv($a, $b, $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isPositive(string $a): bool
    {
        return self::cmp($a, '0') === 1;
    }

    public static function isNegative(string $a): bool
    {
        return self::cmp($a, '0') === -1;
    }

    /** Round half up (away from zero) to $decimals places. */
    public static function round(string $value, int $decimals = 2): string
    {
        $half = '0.'.str_repeat('0', $decimals).'5';
        $offset = self::isNegative($value) ? '-'.$half : $half;

        return bcadd(bcadd($value, $offset, $decimals + 1), '0', $decimals);
    }

    /** Number of significant decimal places (ignoring trailing zeros). */
    /**
     * A database aggregate as an exact decimal string. PostgreSQL returns exact decimals as strings; SQLite
     * returns floats, which are rounded here so float noise (33.330000000000005) never reaches a figure.
     */
    public static function fromDatabase(mixed $value, int $decimals = 2): string
    {
        $text = is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : sprintf('%.4f', (float) $value);

        return self::round($text, $decimals);
    }

    public static function decimals(string $value): int
    {
        $fraction = explode('.', $value)[1] ?? '';

        return strlen(rtrim($fraction, '0'));
    }
}
