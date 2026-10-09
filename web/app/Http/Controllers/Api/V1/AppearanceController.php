<?php

namespace App\Http\Controllers\Api\V1;

use App\Theme\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The look for the Android app: the full resolved palette (the app never derives colours itself), the logo, and the
 * welcome banner meant for the app in the app's language, with today's event when one is meant for this workspace's
 * country (or, before sign-in, the phone's). While an event is on, the usual look comes along as `base`, so the app can
 * go back to it when the event ends even with no connection. Private to the asker (it depends on who asks), and 304
 * when the app already has this look.
 */
final class AppearanceController
{
    public function __invoke(Request $request, Appearance $appearance): JsonResponse
    {
        $look = $appearance->current($request, 'mobile');
        $event = $look->event();
        $base = $appearance->live();
        $language = app()->getLocale();

        $response = response()->json(['data' => [
            'version' => $look->version(),
            'custom' => $look->hasCustomColours(),
            'tokens' => $look->tokens(),
            'logo_url' => $look->imageUrl('logo'),
            'logo_dark_url' => $look->imageUrl('logo_dark'),
            'banner' => $look->banner('mobile', $language),
            'event' => $event === null ? null : ['id' => $event['id'], 'name' => $event['name'], 'ends_on' => $event['ends_on'], 'until' => $event['until']],
            'base' => $event === null ? null : [
                'tokens' => $base->tokens(),
                'logo_url' => $base->imageUrl('logo'),
                'logo_dark_url' => $base->imageUrl('logo_dark'),
                'banner' => $base->banner('mobile', $language),
            ],
        ]]);

        $response->setEtag(sha1((string) $response->getContent()));
        $response->headers->set('Cache-Control', 'private, max-age=300');
        $response->headers->set('Vary', 'Accept-Language, Authorization');
        $response->isNotModified($request);

        return $response;
    }
}
