<?php

namespace App\Http\Controllers\Workspace;

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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The investors pages of the web app (Win Plan PP3). Thin: the rules live in the actions, the policy and the requests. */
final class InvestorController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Investor::class);
        $tenant = $this->tenant();
        MainInvestor::for($tenant);

        $investors = Investor::query()->orderByDesc('is_main')->orderByRaw('archived_at is not null')->orderByRaw('LOWER(name)')->get();

        return view('app.investors.index', [
            'investors' => $investors->map(fn (Investor $investor) => ['model' => $investor, 'summary' => InvestorSummary::for($investor)]),
            'entitlement' => Entitlements::for($tenant)->check(Feature::Investors),
            'currency' => $tenant->currency,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Investor::class);

        return view('app.investors.create', ['currency' => $this->tenant()->currency]);
    }

    public function store(InvestorRequest $request, CreateInvestor $create): RedirectResponse
    {
        Gate::authorize('create', Investor::class);
        /** @var array{name: string, commercial_registration?: ?string, commission_percent?: ?string, notes?: ?string, opening_capital?: ?string} $data */
        $data = $request->validated();

        $investor = $create->handle($this->tenant(), $data, $request->user());

        return redirect()->route('app.investors.show', $investor)->with('status', __(':name was added.', ['name' => $investor->name]));
    }

    public function show(Request $request, Investor $investor): View
    {
        Gate::authorize('view', $investor);

        $entries = InvestorEntry::query()->where('investor_id', $investor->id)->with(['contract', 'createdBy'])
            ->orderByDesc('occurred_on')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $reversed = InvestorEntry::query()->whereIn('reverses_entry_id', $entries->pluck('id'))->pluck('reverses_entry_id')->flip();

        return view('app.investors.show', [
            'investor' => $investor,
            'summary' => InvestorSummary::for($investor),
            'months' => InvestorSummary::profitByMonth($investor),
            'contracts' => InvestorPayload::contracts($investor),
            'entries' => $entries,
            'reversed' => $reversed,
            'currency' => $this->tenant()->currency,
        ]);
    }

    public function update(InvestorRequest $request, Investor $investor, UpdateInvestor $update): RedirectResponse
    {
        Gate::authorize('update', $investor);
        $data = $request->validated();
        $data['archived'] = $request->boolean('archived');

        $update->handle($investor, $data, $request->user());

        return redirect()->route('app.investors.show', $investor)->with('status', __('Saved.'));
    }

    public function storeEntry(InvestorEntryRequest $request, Investor $investor, RecordInvestorEntry $record): RedirectResponse
    {
        Gate::authorize('update', $investor);
        $data = $request->validated();

        $record->handle($investor, $data['type'], $data['amount'], $data['occurred_on'] ?? null, $data['note'] ?? null, $request->user());

        return redirect()->route('app.investors.show', $investor)
            ->with('status', $data['type'] === 'deposit' ? __('The money put in was recorded.') : __('The money taken out was recorded.'));
    }

    public function reverse(Request $request, InvestorEntry $entry, ReverseInvestorEntry $reverse): RedirectResponse
    {
        Gate::authorize('reverse', $entry->investor);

        $reverse->handle($entry, null, $request->user());

        return redirect()->route('app.investors.show', $entry->investor_id)->with('status', __('The entry was reversed.'));
    }

    private function tenant(): Tenant
    {
        return $this->current->get() ?? abort(403);
    }
}
