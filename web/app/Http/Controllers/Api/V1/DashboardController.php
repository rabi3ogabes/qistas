<?php

namespace App\Http\Controllers\Api\V1;

use App\Reports\DashboardMetrics;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/** The headline numbers (see DashboardMetrics for exactly how each is defined) and who owes money today. */
final class DashboardController
{
    public function __invoke(CurrentTenant $current, DashboardMetrics $metrics): JsonResponse
    {
        $tenant = $current->get();

        return response()->json(['data' => ['currency' => $tenant->currency, ...$metrics->for($tenant)]]);
    }
}
