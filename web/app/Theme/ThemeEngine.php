<?php

declare(strict_types=1);

namespace App\Theme;

use InvalidArgumentException;

/**
 * The theme engine's palette rules (docs/THEME_ENGINE.md), a port of brand/shared/qistas-theme.js: from the few colours
 * an admin chooses (the "pins") to the full 26-token palette in light and dark, readable by construction.
 *
 *  - A look stores only what it changes. Everything that depends on a pinned colour is derived: text on it, the hero
 *    gradient, the whole dark mode, an accessible shade of the accent.
 *  - A contrast gate (22 pairs, each mode) finds anything unreadable; the repair moves only the foreground.
 *
 * The factory palette is the base theme of the engine (and of resources/css/tokens.css). shared/theme-vectors.json holds
 * the reference engine's answers, and tests/Unit/ThemeEngineTest.php holds this class to them.
 */
final class ThemeEngine
{
    public const MODES = ['light', 'dark'];

    /** The factory look: Qistas Signature, light and dark. */
    public const BASE = [
        'light' => [
            'primary' => '#0B1F44',
            'onPrimary' => '#F7F3EA',
            'action' => '#0B1F44',
            'onAction' => '#F7F3EA',
            'accent' => '#C9A25B',
            'onAccent' => '#0B1F44',
            'accentText' => '#7F6126',
            'info' => '#1C6BA4',
            'onInfo' => '#FFFFFF',
            'bg' => '#F7F3EA',
            'surface' => '#FFFFFF',
            'surfaceAlt' => '#EFE9DB',
            'ink' => '#0B1F44',
            'inkMuted' => '#4F5B76',
            'line' => '#E3DCCB',
            'positive' => '#176E50',
            'warning' => '#9A5700',
            'danger' => '#B3261E',
            'tintSky' => '#DDEDFA',
            'tintBlush' => '#F6E4EA',
            'tintSand' => '#F8EBCB',
            'tintMint' => '#DCF2E8',
            'heroFrom' => '#0B1F44',
            'heroTo' => '#1B3A78',
            'logoInk' => '#0B1F44',
            'logoAccent' => '#C9A25B',
        ],
        'dark' => [
            'primary' => '#163A7A',
            'onPrimary' => '#F7F3EA',
            'action' => '#C9A25B',
            'onAction' => '#0B1F44',
            'accent' => '#D4AE68',
            'onAccent' => '#0B1F44',
            'accentText' => '#DDBB7A',
            'info' => '#6AAEE0',
            'onInfo' => '#071634',
            'bg' => '#071634',
            'surface' => '#0E2250',
            'surfaceAlt' => '#14295F',
            'ink' => '#F2EEE3',
            'inkMuted' => '#A9B4CC',
            'line' => '#22386B',
            'positive' => '#41C795',
            'warning' => '#EBB04A',
            'danger' => '#F28B82',
            'tintSky' => '#14355F',
            'tintBlush' => '#3A2540',
            'tintSand' => '#3A3320',
            'tintMint' => '#123B31',
            'heroFrom' => '#1A4290',
            'heroTo' => '#0E2A5C',
            'logoInk' => '#F7F3EA',
            'logoAccent' => '#D4AE68',
        ],
    ];

    /** [foreground, background, minimum ratio, label, blocking]: what must stay readable. */
    private const CHECKS = [
        ['ink', 'bg', 4.5, 'Text on background', true],
        ['ink', 'surface', 4.5, 'Text on card', true],
        ['inkMuted', 'bg', 4.5, 'Muted text on background', true],
        ['inkMuted', 'surface', 4.5, 'Muted text on card', true],
        ['onPrimary', 'primary', 4.5, 'Text on primary', true],
        ['onAction', 'action', 4.5, 'Text on button', true],
        ['onAccent', 'accent', 4.5, 'Text on accent', true],
        ['accentText', 'bg', 4.5, 'Accent text on background', true],
        ['accentText', 'surface', 4.5, 'Accent text on card', true],
        ['info', 'bg', 4.5, 'Link on background', true],
        ['info', 'surface', 4.5, 'Link on card', true],
        ['onInfo', 'info', 4.5, 'Text on interactive', true],
        ['positive', 'surface', 4.5, 'Paid label on card', true],
        ['warning', 'surface', 4.5, 'Due label on card', true],
        ['danger', 'surface', 4.5, 'Overdue label on card', true],
        ['ink', 'tintSky', 4.5, 'Text on sky tile', true],
        ['ink', 'tintBlush', 4.5, 'Text on blush tile', true],
        ['ink', 'tintSand', 4.5, 'Text on sand tile', true],
        ['ink', 'tintMint', 4.5, 'Text on mint tile', true],
        ['onPrimary', 'heroFrom', 4.5, 'Text on hero (start)', true],
        ['onPrimary', 'heroTo', 4.5, 'Text on hero (end)', true],
        ['logoInk', 'bg', 3.0, 'Logo on background', false],
    ];

    /** Status text sits on a 14% tint of itself over the card (the Paid, Due and Overdue chips). */
    private const STATUS = [['positive', 'Paid chip text'], ['warning', 'Due chip text'], ['danger', 'Overdue chip text']];

    private const TINT = 0.14;

    /** Neutral text tokens snap back to the brand ink and ivory when far from readable; semantic colours keep their hue. */
    private const NEUTRAL_FG = ['ink', 'inkMuted', 'onPrimary', 'onAction', 'onAccent', 'onInfo', 'logoInk'];

    /**
     * The palette for a set of pinned colours.
     *
     * @param  array<string, mixed>  $pins  only what the admin chose, as ['light' => [token => '#RRGGBB'], 'dark' => [...]]; checked here, as it arrives from a form
     * @param  bool  $repair  fix anything unreadable (always on in production; off to see the raw result)
     * @return array{light: array<string, string>, dark: array<string, string>}
     *
     * @throws InvalidArgumentException for an unknown mode or token, or a colour that is not #RRGGBB
     */
    public static function resolve(array $pins, bool $repair = true): array
    {
        $tokens = self::BASE;
        $explicit = ['light' => [], 'dark' => []];

        foreach ($pins as $mode => $set) {
            if (! in_array($mode, self::MODES, true) || ! is_array($set)) {
                throw new InvalidArgumentException("Unknown mode [{$mode}].");
            }

            foreach ($set as $token => $colour) {
                if (! array_key_exists($token, self::BASE['light'])) {
                    throw new InvalidArgumentException("Unknown colour [{$token}].");
                }

                $tokens[$mode][$token] = Color::normalise((string) $colour);
                $explicit[$mode][$token] = true;
            }
        }

        self::deriveLight($tokens['light'], $explicit['light']);
        self::deriveDark($tokens['dark'], $tokens['light'], $explicit['dark'], $explicit['light']);

        return $repair ? self::autoFix($tokens)['tokens'] : $tokens;
    }

    /**
     * Every contrast pair that must pass, for each mode, with the ratio found.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>}  $tokens
     * @return list<array{mode: string, fg: string, bg: string, bgHex: string, min: float, label: string, blocking: bool, ratio: float, pass: bool}>
     */
    public static function validate(array $tokens): array
    {
        $out = [];

        foreach (self::MODES as $mode) {
            foreach (self::pairs($tokens[$mode]) as $pair) {
                $ratio = Color::contrast($tokens[$mode][$pair['fg']], $pair['bgHex']);
                $out[] = [
                    'mode' => $mode, 'fg' => $pair['fg'], 'bg' => $pair['bg'], 'bgHex' => $pair['bgHex'], 'min' => $pair['min'],
                    'label' => $pair['label'], 'blocking' => $pair['blocking'],
                    'ratio' => floor($ratio * 100 + 0.5) / 100, 'pass' => $ratio >= $pair['min'],
                ];
            }
        }

        return $out;
    }

    /**
     * Fixes every failing pair by moving the foreground token, and says what moved.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>}  $tokens
     * @return array{tokens: array{light: array<string, string>, dark: array<string, string>}, changed: list<array{mode: string, token: string, from: string, to: string}>}
     */
    public static function autoFix(array $tokens): array
    {
        $changed = [];

        foreach (self::MODES as $mode) {
            $t = &$tokens[$mode];

            for ($pass = 0; $pass < 3; $pass++) {
                // The list of pairs is taken once per pass, as the reference does: a fix made part-way does not re-colour a later pair's background.
                foreach (self::pairs($t) as $pair) {
                    if (Color::contrast($t[$pair['fg']], $pair['bgHex']) >= $pair['min']) {
                        continue;
                    }

                    $before = $t[$pair['fg']];
                    $t[$pair['fg']] = str_ends_with($pair['bg'], 'Tint')
                        ? self::fixStatus($t, $pair['fg'], $pair['min'])
                        : self::fixForeground($pair['fg'], $t[$pair['fg']], $pair['bgHex'], $pair['min']);

                    if ($t[$pair['fg']] !== $before) {
                        $changed[] = ['mode' => $mode, 'token' => $pair['fg'], 'from' => $before, 'to' => $t[$pair['fg']]];
                    }
                }
            }

            unset($t);
        }

        return ['tokens' => $tokens, 'changed' => $changed];
    }

    /**
     * @param  array<string, string>  $t
     * @return list<array{fg: string, bg: string, bgHex: string, min: float, label: string, blocking: bool}>
     */
    private static function pairs(array $t): array
    {
        $out = [];

        foreach (self::CHECKS as [$fg, $bg, $min, $label, $blocking]) {
            if (isset($t[$fg], $t[$bg])) {
                $out[] = ['fg' => $fg, 'bg' => $bg, 'bgHex' => $t[$bg], 'min' => $min, 'label' => $label, 'blocking' => $blocking];
            }
        }

        foreach (self::STATUS as [$status, $label]) {
            if (isset($t[$status], $t['surface'])) {
                $out[] = ['fg' => $status, 'bg' => $status.'Tint', 'bgHex' => Color::mix($t['surface'], $t[$status], self::TINT), 'min' => 4.5, 'label' => $label, 'blocking' => true];
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $t */
    private static function fixStatus(array $t, string $name, float $min): string
    {
        [$h, $s, $l] = Color::hexToHsl($t[$name]);
        $direction = Color::luminance($t['surface']) > 0.4 ? -1 : 1;

        for ($i = 0; $i <= 60; $i++) {
            $c = Color::hslToHex($h, $s, Color::clamp($l + $direction * $i * 0.01, 0, 1));

            if (Color::contrast($c, Color::mix($t['surface'], $c, self::TINT)) >= $min + 0.05) {
                return $c;
            }
        }

        return $t[$name];
    }

    private static function fixForeground(string $name, string $fg, string $bg, float $min): string
    {
        $nudged = Color::ensureContrast($fg, $bg, $min + 0.05);

        if (! in_array($name, self::NEUTRAL_FG, true)) {
            return $nudged;
        }

        if (abs(Color::hexToHsl($nudged)[2] - Color::hexToHsl($fg)[2]) <= 0.3) {
            return $nudged;
        }

        $base = Color::readableOn($bg);

        return $name === 'inkMuted' ? Color::ensureContrast(Color::mix($base, $bg, 0.3), $bg, $min + 0.05) : $base;
    }

    /**
     * @param  array<string, string>  $L  the light palette, changed in place
     * @param  array<string, bool>  $ex  the tokens the admin pinned
     */
    private static function deriveLight(array &$L, array $ex): void
    {
        if (isset($ex['primary'])) {
            $L['heroFrom'] = isset($ex['heroFrom']) ? $L['heroFrom'] : $L['primary'];
            $L['heroTo'] = isset($ex['heroTo']) ? $L['heroTo'] : self::heroEnd($L['primary']);
            $L['action'] = isset($ex['action']) ? $L['action'] : $L['primary'];
            $L['logoInk'] = isset($ex['logoInk']) ? $L['logoInk'] : $L['primary'];
        }

        if ((isset($ex['primary']) || isset($ex['heroFrom']) || isset($ex['heroTo'])) && ! isset($ex['onPrimary'])) {
            $L['onPrimary'] = Color::readableOnAll([$L['primary'], $L['heroFrom'], $L['heroTo']]);
        }

        if ((isset($ex['primary']) || isset($ex['action'])) && ! isset($ex['onAction'])) {
            $L['onAction'] = Color::readableOn($L['action']);
        }

        if (isset($ex['accent'])) {
            if (! isset($ex['onAccent'])) {
                $L['onAccent'] = Color::readableOn($L['accent']);
            }
            if (! isset($ex['accentText'])) {
                $L['accentText'] = Color::ensureContrast(Color::darken($L['accent'], 0.04), Color::darkerOf($L['bg'], $L['surface']), 4.6);
            }
            if (! isset($ex['logoAccent'])) {
                $L['logoAccent'] = $L['accent'];
            }
        }

        if (isset($ex['info']) && ! isset($ex['onInfo'])) {
            $L['onInfo'] = Color::readableOn($L['info']);
        }
    }

    /**
     * The light end of the hero gradient: the main colour lightened by 10 %. Beyond the reference engine: a main colour
     * whose green carries much of its brightness (a national-day green) becomes so bright at 10 % that no text colour
     * reads on both the main colour and the end, and the look could never be published; then the end is lightened only
     * as far as one text colour still reads on both. Every look the reference can make readable takes the first return,
     * so the shared vectors hold.
     */
    private static function heroEnd(string $primary): string
    {
        $readsOnBoth = function (string $end) use ($primary): bool {
            $text = Color::readableOnAll([$primary, $end]);

            return Color::contrast($text, $primary) >= 4.5 && Color::contrast($text, $end) >= 4.5;
        };

        $end = Color::lighten($primary, 0.1);

        if ($readsOnBoth($end) || ! $readsOnBoth($primary)) {
            return $end;
        }

        for ($step = 9; $step >= 1; $step--) {
            $softer = Color::lighten($primary, $step / 100);

            if ($readsOnBoth($softer)) {
                return $softer;
            }
        }

        return $primary;
    }

    /**
     * @param  array<string, string>  $D  the dark palette, changed in place
     * @param  array<string, string>  $L  the light palette (already derived)
     * @param  array<string, bool>  $exD  tokens pinned for dark
     * @param  array<string, bool>  $exL  tokens pinned for light
     */
    private static function deriveDark(array &$D, array $L, array $exD, array $exL): void
    {
        if (isset($exL['primary']) || isset($exD['primary'])) {
            [$h, $s0] = Color::hexToHsl(isset($exD['primary']) ? $D['primary'] : $L['primary']);
            $s = Color::clamp($s0, 0.25, 0.6);

            $set = function (string $token, float $saturation, float $lightness) use (&$D, $exD, $h): void {
                if (! isset($exD[$token])) {
                    $D[$token] = Color::hslToHex($h, $saturation, $lightness);
                }
            };
            $set('bg', $s * 0.8, 0.085);
            $set('surface', $s * 0.72, 0.135);
            $set('surfaceAlt', $s * 0.68, 0.18);
            $set('line', $s * 0.5, 0.26);
            $set('primary', $s * 0.9, 0.24);
            $set('heroFrom', $s, 0.27);
            $set('heroTo', $s, 0.17);

            if (! isset($exD['onPrimary'])) {
                $D['onPrimary'] = Color::readableOnAll([$D['primary'], $D['heroFrom'], $D['heroTo']]);
            }
        }

        if (isset($exL['accent']) || isset($exD['accent'])) {
            if (! isset($exD['accent'])) {
                $D['accent'] = Color::mix($L['accent'], '#FFFFFF', 0.1);
            }
            if (! isset($exD['onAccent'])) {
                $D['onAccent'] = Color::readableOn($D['accent']);
            }
            if (! isset($exD['accentText'])) {
                $D['accentText'] = Color::ensureContrast($D['accent'], Color::lighterOf($D['bg'], $D['surface']), 4.6);
            }
            if (! isset($exD['logoAccent'])) {
                $D['logoAccent'] = $D['accent'];
            }
            if (! isset($exD['action'])) {
                $D['action'] = $D['accent'];
            }
            if (! isset($exD['onAction'])) {
                $D['onAction'] = Color::readableOn($D['action']);
            }
        }

        if (isset($exL['info']) || isset($exD['info'])) {
            if (! isset($exD['info'])) {
                $D['info'] = Color::ensureContrast(Color::mix($L['info'], '#FFFFFF', 0.35), $D['bg'], 4.6);
            }
            if (! isset($exD['onInfo'])) {
                $D['onInfo'] = Color::readableOn($D['info']);
            }
        }
    }
}
