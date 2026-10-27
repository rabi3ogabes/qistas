<?php

namespace App\Http\Controllers\Workspace;

use App\Billing\BillingSettings;
use App\Billing\BillingState;
use App\Billing\Card\CardManagement;
use App\Billing\Card\CardRefused;
use App\Billing\Card\CardSettings;
use App\Billing\Card\CardUnavailable;
use App\Billing\Trials;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Site\PricingCatalog;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Plan and billing": what the workspace is on, until when and how it was paid, how much of it is used, what the other
 * plan adds, and, for the owner while the platform has it on, paying by bank transfer (Win Plan PP13).
 */
final class BillingController
{
    public function __invoke(Request $request, CurrentTenant $tenants, PricingCatalog $catalog, BillingSettings $settings, BillingState $state, CardManagement $cards, CardSettings $cardSettings, Trials $trials): View
    {
        $tenant = $tenants->get();
        abort_if($tenant === null, 403);

        $entitlements = Entitlements::for($tenant);
        $current = $tenant->currentPlan();
        $subscription = $tenant->subscription()->first();
        $canBuy = BillingState::canBuy($tenant, $request->user());

        // Pro by card (spec 2026-10-27 §4.3): read from Stripe when shown; the page still opens when Stripe does not answer.
        [$card, $receipts, $cardUnavailable] = [null, [], false];
        if ($canBuy) {
            try {
                $card = $cards->overview($tenant);
                $receipts = $card === null ? [] : $cards->receipts($tenant);
            } catch (CardUnavailable|CardRefused) {
                $cardUnavailable = $subscription?->source === 'stripe';
            }
        }

        return view('app.billing', [
            'card' => $card,
            'receipts' => $receipts,
            'cardUnavailable' => $cardUnavailable,
            'cardOpen' => $state->cardOpen($tenant, $request->user()),
            'trialDays' => $trials->available($tenant) ? $cardSettings->trialDays() : 0,
            'current' => $current,
            'subscription' => $subscription !== null && $subscription->isCurrent() && ! $current->is_default ? $subscription : null,
            'usage' => [
                'customers' => $entitlements->check(Feature::Customers),
                'contracts' => $entitlements->check(Feature::ActiveContracts),
            ],
            'offers' => $catalog->offers(),
            'features' => Feature::planFeatures(),
            'supportEmail' => config('qistas.support_email'),
            'tenantName' => $tenant->name,
            'transfer' => $canBuy && $entitlements->check(Feature::ManualBilling)->enabled(),
            'bankDetails' => $settings->bankDetails(),
            'paidPlans' => Plan::query()->where('is_default', false)->where('is_public', true)->orderBy('sort_order')->get()
                ->filter(fn (Plan $plan) => ($plan->price_monthly !== null && Money::isPositive($plan->price_monthly)) || ($plan->price_yearly !== null && Money::isPositive($plan->price_yearly)))->values(),
            'requests' => $canBuy ? ManualPaymentRequest::query()->with('plan')->latest()->limit(5)->get() : collect(),
        ]);
    }
}
