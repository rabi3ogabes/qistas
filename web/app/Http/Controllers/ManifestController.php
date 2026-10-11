<?php

namespace App\Http\Controllers;

use App\Support\Locale;
use App\Theme\ThemeEngine;
use Illuminate\Http\JsonResponse;

/**
 * The web app's manifest (Win Plan PL04): what a browser needs to install it on a phone or a computer. It opens at /app,
 * in its own window, in the colours of the app's canvas, with the brand icon (one version safe to crop into any shape).
 */
final class ManifestController
{
    public function __invoke(): JsonResponse
    {
        $canvas = ThemeEngine::BASE['light']['bg'];

        return response()->json([
            'id' => '/app',
            'name' => config('qistas.app_name'),
            'short_name' => config('qistas.app_name'),
            'description' => __('Customers, contracts and payments for instalment businesses.'),
            'lang' => app()->getLocale(),
            'dir' => Locale::direction(),
            'start_url' => '/app',
            'scope' => '/app',
            'display' => 'standalone',
            'background_color' => $canvas,
            'theme_color' => $canvas,
            'categories' => ['business', 'finance', 'productivity'],
            'icons' => [
                ['src' => '/brand/app-icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/brand/app-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/brand/app-icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
