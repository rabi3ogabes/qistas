<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CloseDay;
use App\Actions\ReopenDay;
use App\Documents\BusinessProfile;
use App\Documents\DocumentOptions;
use App\Documents\DocumentService;
use App\Documents\Templates\DayCloseSummary;
use App\Domain\DayClose\DayTotals;
use App\Http\Requests\CloseDayRequest;
use App\Http\Requests\DocumentRequest;
use App\Http\Resources\DayCloseResource;
use App\Models\DayClose;
use App\Models\User;
use App\Settings\TenantSettings;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The end-of-day close (daily-habit wave A, H4): what today looks like, closing a day, reopening it, the history, and the
 * signed summary as a PDF. Every rule is the actions' (CloseDay, ReopenDay) and the policy's (DayClosePolicy); this only
 * carries what the person sent to them and what they answered back. All of it sits behind the `day_close` feature.
 */
final class DayCloseController
{
    /**
     * Today's close, if there is one, and the figures the person is about to sign: the same ones the close will keep.
     * The closing time is the workspace's setting, which tells the app when to ask.
     */
    public function today(Request $request, CurrentTenant $current): JsonResponse
    {
        Gate::authorize('viewAny', DayClose::class);
        $tenant = $current->get() ?? abort(404);
        $user = $this->user($request);

        $day = WorkspaceDay::of($tenant);
        // The day's latest version: a close that was reopened stands for nothing until the day is closed again.
        $latest = DayClose::query()->with(['closedBy', 'reopenedBy'])->where('day', $day->date)->orderByDesc('version')->first();
        $closed = $latest?->status === 'closed';
        $figures = DayTotals::for($tenant, $day);

        return response()->json(['data' => [
            'day' => $day->date,
            'closing_time' => (string) TenantSettings::for($tenant)->get(DayClose::CLOSING_TIME_SETTING),
            'closed' => $closed,
            'close' => $latest === null ? null : (new DayCloseResource($latest))->resolve($request),
            'expected_cash' => $figures['expected_cash'],
            // Maps stay maps when they are empty: an app reads `{}`, never `[]`, for these.
            'by_method' => DayCloseResource::map($figures['by_method']),
            'count' => $figures['count'],
            'per_member' => DayCloseResource::map($figures['per_member']),
            'non_cash' => $figures['non_cash'],
            'watch' => $figures['watch'],
            'can_close' => ! $closed && $user->can('create', DayClose::class),
            'can_reopen' => $latest !== null && $closed && $user->can('reopen', $latest),
        ]]);
    }

    /** Closes a day: today, or the one the request names. A second close of the day is a validation error. */
    public function store(CloseDayRequest $request, CurrentTenant $current, CloseDay $closeDay): JsonResponse
    {
        $tenant = $current->get() ?? abort(404);

        $close = $closeDay->handle($tenant, $request->day($tenant), $request->countedCash(), $request->note(), $this->user($request));

        return $this->show($close)->response()->setStatusCode(201);
    }

    /** Reopens a closed day, with a reason; the day can then be closed again, as the next version. */
    public function reopen(Request $request, DayClose $close, ReopenDay $reopenDay): DayCloseResource
    {
        Gate::authorize('reopen', $close);
        // How long a reason may be is the action's to say (as it is for how short), so the web page cannot disagree.
        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->show($reopenDay->handle($close, $data['reason'], $this->user($request)));
    }

    /** Every close, the newest day first and, within a day, the latest version first. */
    public function index(Request $request, CurrentTenant $current): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', DayClose::class);
        $tenant = $current->get() ?? abort(404);

        $page = DayClose::query()->with(['closedBy', 'reopenedBy'])
            ->orderByDesc('day')->orderByDesc('version')
            ->paginate(PerPage::of($request))->withQueryString();

        // Every row's sentence names the shop: asked once for the page, not once for each row.
        $shop = BusinessProfile::for($tenant)->name(app()->getLocale());
        $page->getCollection()->each(fn (DayClose $close) => $close->setAttribute('shop_name', $shop));

        return DayCloseResource::collection($page);
    }

    /** The close as a signed PDF (a document like the others: it counts against the plan's allowance, and its QR opens /verify). */
    public function summary(DocumentRequest $request, DayClose $close, DocumentService $documents, CurrentTenant $current): Response
    {
        Gate::authorize('view', $close);
        $tenant = $current->get() ?? abort(404);
        $user = $this->user($request);

        $issued = $documents->issue(new DayCloseSummary($close), DocumentOptions::from($request->validated(), $tenant, $user), $tenant, $user);

        return response($issued['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$issued['filename'].'"',
            'X-Document-Code' => $issued['document']->verification_code,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function show(DayClose $close): DayCloseResource
    {
        return new DayCloseResource($close->load(['closedBy', 'reopenedBy']));
    }

    private function user(Request $request): User
    {
        return $request->user() ?? abort(401);
    }
}
