<?php

namespace App\Http\Controllers\Api\V1;

use App\Activity\ActivityFeed;
use App\Http\Requests\ActivityRequest;
use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/** The activity log (Win Plan PP10): what the business's people did, newest first. Owners and managers. */
final class ActivityController
{
    public function __invoke(ActivityRequest $request, CurrentTenant $current): JsonResponse
    {
        $tenant = $current->get() ?? abort(404);
        $page = ActivityFeed::page($tenant, $request->filters());
        $people = ActivityFeed::people($tenant);
        $names = array_column($people, 'name', 'id');

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $entry) => ActivityFeed::present($entry, $tenant, $names))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'people' => $people, 'kinds' => array_keys(ActivityFeed::KINDS)],
        ]);
    }
}
