<?php

namespace App\Http\Controllers\Workspace;

use App\Activity\ActivityFeed;
use App\Http\Requests\ActivityRequest;
use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\View\View;

/** The activity log page (Win Plan PP10): who did what, newest first, with filters. Owners and managers. */
final class ActivityController
{
    public function __invoke(ActivityRequest $request, CurrentTenant $current): View
    {
        $tenant = $current->get() ?? abort(404);
        $filters = $request->filters();
        $page = ActivityFeed::page($tenant, $filters);
        $people = ActivityFeed::people($tenant);
        $names = array_column($people, 'name', 'id');

        return view('app.activity', [
            'page' => $page,
            'entries' => collect($page->items())->map(fn (AuditLog $entry) => ActivityFeed::present($entry, $tenant, $names)),
            'people' => $people,
            'filters' => $filters,
        ]);
    }
}
