<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Transaction;
use App\Reports\DashboardMetrics;
use App\Tenancy\CurrentTenant;
use Illuminate\View\View;

final class DashboardController
{
    public function __invoke(CurrentTenant $current, DashboardMetrics $metrics): View
    {
        $tenant = $current->get();
        abort_if($tenant === null, 403);

        // The first-run checklist: three real steps, shown until the business has all three.
        $steps = [
            'customer' => Customer::query()->exists(),
            'contract' => Contract::query()->exists(),
            'payment' => Transaction::query()->where('type', 'payment')->exists(),
        ];

        return view('app.dashboard', [
            'metrics' => $metrics->for($tenant),
            'currency' => $tenant->currency,
            'stepsDone' => $steps,
            'gettingStarted' => in_array(false, $steps, true),
        ]);
    }
}
