<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Investors\CreateInvestor;
use App\Actions\Investors\RecordInvestorEntry;
use App\Actions\Investors\ReverseInvestorEntry;
use App\Actions\Investors\UpdateInvestor;
use App\Domain\Investors\InvestorSummary;
use App\Domain\Investors\MainInvestor;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Api\InvestorPayload;
use App\Http\Requests\InvestorEntryRequest;
use App\Http\Requests\InvestorRequest;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Who funds the business (Win Plan PP3): the investors with their figures, one investor's page, adding and changing
 * investors, deposits and withdrawals, and reversing a mistaken one. Reading stays open when the feature is switched
 * off (nothing disappears); everything that writes asks for the feature (the actions check it).
 */
final class InvestorController
{
    public function index(Request $request, CurrentTenant $current): JsonResponse
    {
        Gate::authorize('viewAny', Investor::class);
        $tenant = $this->tenant($current);
        MainInvestor::for($tenant);
        $entitlement = Entitlements::for($tenant)->check(Feature::Investors);

        $investors = Investor::query()->orderByDesc('is_main')->orderByRaw('archived_at is not null')->orderByRaw('LOWER(name)')->get();

        return response()->json(['data' => [
            'investors' => $investors->map(fn (Investor $investor) => InvestorPayload::investor($investor))->all(),
            'can_manage' => Gate::allows('create', Investor::class),
            'can_reverse' => $request->user()->roleIn($tenant->id)?->canDelete() ?? false,
            'limit' => $entitlement->limit(),
            'used' => $entitlement->used(),
        ]]);
    }

    public function store(InvestorRequest $request, CurrentTenant $current, CreateInvestor $create): JsonResponse
    {
        Gate::authorize('create', Investor::class);
        /** @var array{name: string, commercial_registration?: ?string, commission_percent?: ?string, notes?: ?string, opening_capital?: ?string} $data */
        $data = $request->validated();

        $investor = $create->handle($this->tenant($current), $data, $request->user());

        return response()->json(['data' => InvestorPayload::investor($investor)], 201);
    }

    public function show(Investor $investor): JsonResponse
    {
        Gate::authorize('view', $investor);

        $entries = InvestorEntry::query()->where('investor_id', $investor->id)->with('contract')
            ->orderByDesc('occurred_on')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $reversed = InvestorEntry::query()->whereIn('reverses_entry_id', $entries->pluck('id'))->pluck('reverses_entry_id')->flip();

        return response()->json(['data' => [
            ...InvestorPayload::investor($investor),
            'profit_by_month' => InvestorSummary::profitByMonth($investor),
            'contracts' => InvestorPayload::contracts($investor),
            'entries' => $entries->map(fn (InvestorEntry $entry) => InvestorPayload::entry($entry, $reversed->has($entry->id)))->all(),
        ]]);
    }

    public function update(InvestorRequest $request, Investor $investor, UpdateInvestor $update): JsonResponse
    {
        Gate::authorize('update', $investor);

        return response()->json(['data' => InvestorPayload::investor($update->handle($investor, $request->validated(), $request->user()))]);
    }

    public function storeEntry(InvestorEntryRequest $request, Investor $investor, RecordInvestorEntry $record): JsonResponse
    {
        Gate::authorize('update', $investor);
        $data = $request->validated();

        $entry = $record->handle($investor, $data['type'], $data['amount'], $data['occurred_on'] ?? null, $data['note'] ?? null, $request->user());

        return response()->json(['data' => InvestorPayload::entry($entry)], 201);
    }

    public function reverse(Request $request, InvestorEntry $entry, ReverseInvestorEntry $reverse): JsonResponse
    {
        Gate::authorize('reverse', $entry->investor);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => InvestorPayload::entry($reverse->handle($entry, $data['note'] ?? null, $request->user()))], 201);
    }

    private function tenant(CurrentTenant $current): Tenant
    {
        return $current->get() ?? abort(403);
    }
}
