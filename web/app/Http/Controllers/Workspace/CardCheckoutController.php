<?php

namespace App\Http\Controllers\Workspace;

use App\Billing\BillingState;
use App\Billing\Card\CardCheckout;
use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\StripePrices;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\CardCheckoutRequest;
use App\Models\Plan;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Pro by card on the website (spec 2026-10-27 §4.1, §4.2): the checkout page, the checkout it opens in Stripe, and the
 * way back from paying. The owner only; the app never comes here.
 */
final class CardCheckoutController
{
    public function show(Request $request, CurrentTenant $tenants, CardSettings $settings): View|RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);
        abort_unless(Entitlements::for($tenant)->check(Feature::CardBilling)->enabled() && $settings->ready(), 404);
        if (BillingState::paidThrough($tenant) !== null) {
            return redirect()->route('app.billing')->with('status', __('Pro is already on for this workspace.'));
        }

        $plan = Plan::query()->where('key', (string) $request->query('plan', 'pro'))->where('is_default', false)->first();
        abort_if($plan === null, 404);
        $period = in_array($request->query('period'), StripePrices::PERIODS, true) ? (string) $request->query('period') : 'year';

        return view('app.billing.checkout', ['plan' => $plan, 'period' => $period, 'attempt' => 'card-'.Str::uuid()]);
    }

    public function session(CardCheckoutRequest $request, CurrentTenant $tenants, CardCheckout $checkout): JsonResponse
    {
        $tenant = $this->owned($request, $tenants);

        try {
            $opened = $checkout->start($tenant, $request->user(), (string) $request->validated('plan'), (string) $request->validated('period'), (string) $request->validated('attempt'));
        } catch (CardRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (CardUnavailable) {
            return response()->json(['message' => __('Card payments are briefly unavailable. Nothing was charged; try again in a minute.')], 503);
        }

        return response()->json(['client_secret' => $opened['client_secret'], 'publishable_key' => $opened['publishable_key']]);
    }

    /** Back from paying. Plan Task 7 confirms the payment here; until then the billing page shows where things stand. */
    public function return(Request $request, CurrentTenant $tenants): RedirectResponse
    {
        $this->owned($request, $tenants);

        return redirect()->route('app.billing');
    }

    private function owned(Request $request, CurrentTenant $tenants): Tenant
    {
        $tenant = $tenants->get();
        abort_if($tenant === null || ! BillingState::canBuy($tenant, $request->user()), 403);

        return $tenant;
    }
}
