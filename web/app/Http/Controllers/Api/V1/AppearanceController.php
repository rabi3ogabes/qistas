<?php

namespace App\Http\Controllers\Api\V1;

use App\Theme\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The look for the Android app: the full resolved palette (the app never derives colours itself), the logo, and the
 * welcome banner meant for the app in the app's language. Public: nothing here is private. Answers 304 when the app
 * already has this look.
 */
final class AppearanceController
{
    public function __invoke(Request $request, Appearance $appearance): JsonResponse
    {
        $look = $appearance->live();

        $response = response()->json(['data' => [
            'version' => $look->version(),
            'custom' => $look->hasCustomColours(),
            'tokens' => $look->tokens(),
            'logo_url' => $look->imageUrl('logo'),
            'logo_dark_url' => $look->imageUrl('logo_dark'),
            'banner' => $look->banner('mobile', app()->getLocale()),
        ]]);

        $response->setEtag(sha1((string) $response->getContent()));
        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('Vary', 'Accept-Language');
        $response->isNotModified($request);

        return $response;
    }
}
