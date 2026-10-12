<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Reports\Ageing;
use App\Reports\CashForecast;
use App\Reports\ProfitReport;
use App\Reports\ReportFilters;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The reports (Win Plan K8, RP14, RP15, CT27) for the app: the forecast, ageing and profit, each narrowed by the same
 * filters. Everyone in the workspace but collectors (403); part of Pro (402 feature_locked otherwise).
 */
final class ReportController
{
    public function forecast(Request $request, CashForecast $forecast): JsonResponse
    {
        return response()->json(['data' => $forecast->for($this->filters($request))]);
    }

    public function ageing(Request $request, Ageing $ageing): JsonResponse
    {
        return response()->json(['data' => $ageing->for($this->filters($request))]);
    }

    public function profit(Request $request, ProfitReport $profit): JsonResponse
    {
        return response()->json(['data' => $profit->for($this->filters($request))]);
    }

    private function filters(Request $request): ReportFilters
    {
        $tenant = app(CurrentTenant::class)->get() ?? abort(403);
        Gate::authorize('view-reports');
        Entitlements::for($tenant)->assertEnabled(Feature::AdvancedReports);

        return ReportFilters::fromRequest($request);
    }
}
