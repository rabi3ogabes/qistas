<?php

declare(strict_types=1);

namespace App\Theme;

/**
 * Ready-made looks for the Appearance page: four colours each, chosen to sit well together and to pass every contrast
 * check before any repair. "Qistas" is the factory look, so choosing it clears the colours instead of pinning copies.
 */
final class Presets
{
    /** @var array<string, array{colours: array<string, string>}> */
    public const LIST = [
        'qistas' => ['colours' => []],
        'emerald' => ['colours' => ['primary' => '#0F3D2E', 'accent' => '#B8935A', 'info' => '#1F6F8B', 'bg' => '#F6F4EC']],
        'bordeaux' => ['colours' => ['primary' => '#4A1526', 'accent' => '#C8A46A', 'info' => '#2D5C8A', 'bg' => '#F8F3EE']],
        'ocean' => ['colours' => ['primary' => '#0A3D52', 'accent' => '#D9A441', 'info' => '#1A6E93', 'bg' => '#F4F6F5']],
        'graphite' => ['colours' => ['primary' => '#1E2229', 'accent' => '#C6A15B', 'info' => '#2B65A8', 'bg' => '#F5F4F0']],
        'desert' => ['colours' => ['primary' => '#5A2E2A', 'accent' => '#D4A373', 'info' => '#2F6690', 'bg' => '#FAF5EF']],
    ];

    /**
     * The four colours a preset shows as its swatches (the factory ones for "Qistas").
     *
     * @return array<string, string>
     */
    public static function swatches(string $preset): array
    {
        $chosen = self::LIST[$preset]['colours'] ?? [];
        $out = [];

        foreach (Appearance::COLOURS as $name) {
            $out[$name] = $chosen[$name] ?? ThemeEngine::BASE['light'][$name];
        }

        return $out;
    }
}
