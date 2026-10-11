<?php

namespace App\Http\Controllers;

use App\Billing\Store\GooglePushAuth;
use App\Billing\Store\StoreUnavailable;
use App\Billing\StoreSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Google Play's real-time developer notifications, pushed by Google Cloud Pub/Sub (Win Plan PP13). A push Google did
 * not sign for this endpoint is refused; anything else is answered 204 once handled (Pub/Sub then stops sending it),
 * or 503 when Google Play could not be asked, so Pub/Sub sends it again later.
 */
final class GooglePlayWebhookController
{
    public function __invoke(Request $request, GooglePushAuth $auth, StoreSubscriptions $store): Response
    {
        abort_unless($auth->accepts($request->bearerToken()), 403);

        try {
            $store->notify($request->json()->all());
        } catch (StoreUnavailable) {
            return response()->noContent(503);
        }

        return response()->noContent();
    }
}
