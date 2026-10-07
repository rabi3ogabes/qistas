<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\CancelContract;
use App\Actions\CreateContract;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\CancelContractRequest;
use App\Http\Requests\ContractRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Transaction;
use App\Reports\ContractProgress;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The workspace's contracts. Thin: the rules live in the actions, the policy and the requests. */
final class ContractController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request, ContractProgress $progress): View
    {
        Gate::authorize('viewAny', Contract::class);

        $term = trim((string) $request->query('q', ''));
        $requested = $request->query('status');
        // A search looks everywhere unless a list was chosen; otherwise the default is what is running.
        $view = is_string($requested) && in_array($requested, Contract::VIEWS, true) ? $requested : ($term !== '' ? 'all' : 'active');

        $contracts = Contract::query()->with('customer')->inView($view)->search($term)->orderByDesc('number')->paginate(20)->withQueryString();
        $counts = $progress->counts();

        return view('app.contracts.index', [
            'contracts' => $contracts,
            'progress' => $progress->forContracts($contracts->pluck('id')->all()),
            'counts' => $counts,
            'view' => $view,
            'term' => $term,
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Contract::class);

        $customers = Customer::query()->orderByRaw('LOWER(name)')->get(['id', 'name', 'phone']);
        $requested = $request->query('customer');

        return view('app.contracts.create', [
            'customers' => $customers,
            // Only a customer of this workspace can be preselected; anything else is ignored.
            'selected' => is_string($requested) ? $customers->firstWhere('id', $requested)?->id : null,
            'usage' => Entitlements::for($this->current->get())->check(Feature::ActiveContracts),
            'currency' => $this->current->get()?->currency,
            'today' => today()->format('Y-m-d'),
        ]);
    }

    public function store(ContractRequest $request, CreateContract $create): RedirectResponse
    {
        $contract = $create->handle($this->current->get(), $request->validated(), $request->user());

        return redirect()->route('app.contracts.show', $contract)
            ->with('status', __('Contract :reference opened.', ['reference' => $contract->reference()]));
    }

    public function show(Contract $contract): View
    {
        Gate::authorize('view', $contract);

        $installments = Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get();
        $lines = Transaction::query()->where('contract_id', $contract->id)->with('createdBy')
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->get();

        $owed = $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->remaining()), '0');
        $paid = $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->paid_amount), '0');
        $next = $installments->first(fn (Installment $i) => $i->status !== 'paid');

        return view('app.contracts.show', [
            'contract' => $contract->load('customer'),
            'installments' => $installments,
            'lines' => $lines,
            // Which payments have been reversed already: they keep their line but can no longer be voided.
            'reversed' => $lines->pluck('reverses_transaction_id')->filter()->flip()->all(),
            'owed' => $contract->status === 'cancelled' ? '0' : $owed,
            'paid' => $paid,
            'next' => $next,
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function cancel(CancelContractRequest $request, Contract $contract, CancelContract $cancel): RedirectResponse
    {
        $cancel->handle($contract, $request->reason(), $request->user());

        return redirect()->route('app.contracts.show', $contract)
            ->with('status', __('Contract :reference cancelled. Its history is kept.', ['reference' => $contract->reference()]));
    }
}
