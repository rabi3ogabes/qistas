<?php

namespace App\Http\Controllers\Workspace;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Site\PricingCatalog;
use App\Tenancy\CurrentTenant;
use Illuminate\View\View;

/**
 * "Plan & billing": what the workspace is on, how much of it is used, and what the other plan adds. Taking payment
 * online is not switched on yet, so the page says honestly how an upgrade happens today instead of pretending.
 */
final class BillingController
{
    public function __invoke(CurrentTenant $current, PricingCatalog $catalog): View
    {
        $tenant = $current->get();
        abort_if($tenant === null, 403);

        $entitlements = Entitlements::for($tenant);

        return view('app.billing', [
            'current' => $tenant->currentPlan(),
            'usage' => [
                'customers' => $entitlements->check(Feature::Customers),
                'contracts' => $entitlements->check(Feature::ActiveContracts),
            ],
            'offers' => $catalog->offers(),
            'features' => Feature::cases(),
            'supportEmail' => config('qistas.support_email'),
            'tenantName' => $tenant->name,
        ]);
    }
}
