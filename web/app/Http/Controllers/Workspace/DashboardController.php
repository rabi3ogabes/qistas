<?php

namespace App\Http\Controllers\Workspace;

use App\Domain\DayClose\DayCloseGuard;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Reports\DashboardMetrics;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController
{
    public function __invoke(Request $request, CurrentTenant $current, DashboardMetrics $metrics): View
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
            // Remind everyone (Win Plan PP9), once the platform has it on.
            'canRemind' => Entitlements::for($tenant)->check(Feature::InstalmentAlerts)->enabled(),
            // The end of the day (daily-habit wave A, H4): offered while it is still to close, to those who may close it.
            'closeTheDay' => $this->dayIsDueToClose($request, $tenant),
            'currency' => $tenant->currency,
            'stepsDone' => $steps,
            'gettingStarted' => in_array(false, $steps, true),
        ]);
    }

    /** Whether to offer closing the day: the workspace has the feature, this person may close, and today is not closed. */
    private function dayIsDueToClose(Request $request, Tenant $tenant): bool
    {
        return Entitlements::for($tenant)->check(Feature::DayClose)->enabled()
            && ($request->user()?->can('create', DayClose::class) ?? false)
            && ! DayCloseGuard::isClosed($tenant, WorkspaceDay::of($tenant));
    }
}
