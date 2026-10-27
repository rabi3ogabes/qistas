<?php

namespace App\Http\Controllers\Workspace;

use App\Billing\BillingState;
use App\Billing\Card\CardCheckout;
use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardSubscriptions;
use App\Billing\Card\CardUnavailable;
use App\Billing\Card\StripeGateway;
use App\Billing\Card\StripePrices;
use App\Billing\Trials;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureStatus;
use App\Http\Requests\CardCheckoutRequest;
use App\Models\Plan;
use App\Models\Tenant;
use App\Site\PlanOffer;
use App\Site\PricingCatalog;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    public function show(Request $request, CurrentTenant $tenants, CardSettings $settings, Trials $trials, PricingCatalog $catalog): View|RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);
        abort_unless(Entitlements::for($tenant)->check(Feature::CardBilling)->enabled() && $settings->ready(), 404);
        if (BillingState::paidThrough($tenant) !== null) {
            return redirect()->route('app.billing')->with('status', __('Pro is already on for this workspace.'));
        }

        $plan = Plan::query()->where('key', (string) $request->query('plan', 'pro'))->where('is_default', false)->first();
        abort_if($plan === null, 404);
        $prices = ['month' => $this->sold($plan->price_monthly), 'year' => $this->sold($plan->price_yearly)];
        $period = in_array($request->query('period'), StripePrices::PERIODS, true) ? (string) $request->query('period') : 'year';
        if ($prices[$period] === null) {
            $period = $period === 'year' ? 'month' : 'year';
        }
        abort_if($prices[$period] === null, 404);

        $trialDays = $trials->available($tenant) ? $settings->trialDays() : 0;
        $offer = $catalog->offers()->first(fn (PlanOffer $o) => $o->plan->is($plan));

        return view('app.billing.checkout', [
            'plan' => $plan,
            'period' => $period,
            'attempt' => 'card-'.Str::uuid(),
            'prices' => $prices,
            'price' => $prices[$period],
            'saving' => $offer?->yearlySavingPercent(),
            'trialDays' => $trialDays,
            'trialEnd' => $trialDays > 0 ? now()->addDays($trialDays) : null,
            'renewsOn' => $period === 'year' ? now()->addYearNoOverflow() : now()->addMonthNoOverflow(),
            'promotionCodes' => $settings->promotionCodes(),
            'unlocked' => $this->unlocked($tenant, $plan, $catalog),
            'email' => (string) $request->user()?->email,
        ]);
    }

    /** A price the plan is sold at: positive, or null. */
    private function sold(?string $price): ?string
    {
        return $price !== null && Money::isPositive($price) ? $price : null;
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

    /**
     * Back from paying (§4.2): Qistas asks Stripe about the checkout now rather than waiting for the webhook, so the
     * owner lands on the plan they bought. A checkout left unpaid goes back to the checkout; another workspace's is 404.
     */
    public function return(Request $request, CurrentTenant $tenants, CardSubscriptions $subscriptions, StripeGateway $stripe, PricingCatalog $catalog): View|RedirectResponse
    {
        $tenant = $this->owned($request, $tenants);
        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId === '') {
            return redirect()->route('app.billing');
        }

        try {
            $state = $subscriptions->syncSession($sessionId, $tenant);
        } catch (ModelNotFoundException|CardRefused) {
            abort(404);
        } catch (CardUnavailable) {
            return redirect()->route('app.billing')->with('warning', __('Stripe could not be reached to confirm your payment. If you paid, Pro turns on within a few minutes.'));
        }

        if ($state !== 'complete') {
            $metadata = $stripe->session($sessionId)->metadata;

            return redirect()->route('app.billing.checkout', ['plan' => $metadata['plan'] ?? 'pro', 'period' => $metadata['period'] ?? 'year'])
                ->with('status', __('Your payment wasn\'t completed; nothing was charged.'));
        }

        $plan = $tenant->currentPlan();
        $subscription = $tenant->subscription()->first();
        if ($plan->is_default || $subscription === null) {
            return redirect()->route('app.billing')->with('status', __('Your payment is being confirmed. Pro turns on in a moment.'));
        }

        return view('app.billing.welcome', [
            'plan' => $plan,
            'subscription' => $subscription,
            'unlocked' => $this->unlocked($tenant, $plan, $catalog),
        ]);
    }

    /**
     * What the plan opens that Free did not, among what the platform has switched on: the welcome page's list.
     *
     * @return list<string> each line as the price list words it
     */
    private function unlocked(Tenant $tenant, Plan $plan, PricingCatalog $catalog): array
    {
        $offers = $catalog->offers();
        $mine = $offers->first(fn (PlanOffer $offer) => $offer->plan->is($plan));
        $free = $offers->first(fn (PlanOffer $offer) => $offer->plan->is_default);
        if ($mine === null) {
            return [];
        }

        $entitlements = Entitlements::for($tenant);
        $lines = [];
        foreach ($mine->features() as $row) {
            $before = $free?->feature($row['feature']);
            $better = $before === null || ! $before['enabled'] || $before['limit'] !== $row['limit'];
            // Before buying, the plan is not the workspace's yet: what counts is whether the platform has it on.
            if ($row['enabled'] && $better && $entitlements->check($row['feature'])->status() !== FeatureStatus::PlatformOff) {
                $lines[] = $row['summary'];
            }
        }

        return array_slice($lines, 0, 8);
    }

    private function owned(Request $request, CurrentTenant $tenants): Tenant
    {
        $tenant = $tenants->get();
        abort_if($tenant === null || ! BillingState::canBuy($tenant, $request->user()), 403);

        return $tenant;
    }
}
