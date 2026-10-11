<?php

namespace App\Http\Controllers\Workspace;

use App\Billing\BillingSettings;
use App\Billing\BillingState;
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
    public function __invoke(Request $request, CurrentTenant $tenants, PricingCatalog $catalog, BillingSettings $settings): View
    {
        $tenant = $tenants->get();
        abort_if($tenant === null, 403);

        $entitlements = Entitlements::for($tenant);
        $current = $tenant->currentPlan();
        $subscription = $tenant->subscription()->first();
        $canBuy = BillingState::canBuy($tenant, $request->user());

        return view('app.billing', [
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
