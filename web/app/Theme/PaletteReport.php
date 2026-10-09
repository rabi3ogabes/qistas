<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * What four chosen colours would become if published: the full palette for light and dark (after the contrast repair,
 * exactly as publish makes it), what the repair would move, and the one contrast that matters most beside each colour
 * field, measured on the colours as chosen so the admin sees the truth about their choice.
 */
final class PaletteReport
{
    /** The pair shown beside each colour field: [foreground token, background token]. */
    private const BESIDE = [
        'primary' => ['onPrimary', 'primary'],
        'accent' => ['onAccent', 'accent'],
        'info' => ['info', 'bg'],
        'bg' => ['ink', 'bg'],
    ];

    private const MIN = 4.5;

    /**
     * @param  array<string, string>  $colours  the chosen colours, #RRGGBB, by name; a missing one is the factory colour
     * @return array{tokens: array{light: array<string, string>, dark: array<string, string>}, changed: list<array{mode: string, token: string, from: string, to: string}>, contrast: array<string, array{ratio: float, pass: bool}>}
     */
    public static function for(array $colours): array
    {
        $raw = ThemeEngine::resolve($colours === [] ? [] : ['light' => $colours], repair: false);
        $fixed = ThemeEngine::autoFix($raw);
        $contrast = [];

        foreach (self::BESIDE as $name => [$fg, $bg]) {
            $ratio = Color::contrast($raw['light'][$fg], $raw['light'][$bg]);
            $contrast[$name] = ['ratio' => floor($ratio * 10 + 0.5) / 10, 'pass' => $ratio >= self::MIN];
        }

        return ['tokens' => $fixed['tokens'], 'changed' => $fixed['changed'], 'contrast' => $contrast];
    }
}
