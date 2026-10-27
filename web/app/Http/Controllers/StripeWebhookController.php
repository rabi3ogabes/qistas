<?php

namespace App\Http\Controllers;

use App\Billing\Card\CardSettings;
use App\Billing\Card\CardSignatureInvalid;
use App\Billing\Card\CardSubscriptions;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Stripe telling us about card subscriptions (spec 2026-10-27 §5, §9). Outside every session: the message proves
 * itself with Stripe's signature over the raw body. 503 while the site has no webhook secret; 400 for anything Stripe
 * did not sign; 500 when Stripe could not be asked back, so Stripe sends the message again (for up to three days).
 */
final class StripeWebhookController
{
    public function __invoke(Request $request, CardSettings $settings, StripeGateway $stripe, CardSubscriptions $subscriptions): JsonResponse
    {
        $secret = $settings->webhookSecret();
        if ($secret === null) {
            return response()->json(['message' => 'Card payments are not set up.'], 503);
        }

        try {
            $event = $stripe->event($request->getContent(), (string) $request->header('Stripe-Signature', ''), $secret);
        } catch (CardSignatureInvalid) {
            return response()->json(['message' => 'The signature does not match.'], 400);
        } catch (CardUnavailable) {
            return response()->json(['message' => 'Card payments are not set up.'], 503);
        }

        try {
            $outcome = $subscriptions->handle($event);
        } catch (CardUnavailable $e) {
            Log::warning('stripe webhook left for a retry', ['event' => $event->id, 'type' => $event->type, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Try again later.'], 500);
        }

        return response()->json(['outcome' => $outcome]);
    }
}
