<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\ArchiveContract;
use App\Actions\CancelContract;
use App\Actions\ConvertToOpen;
use App\Actions\CreateContract;
use App\Actions\Paperwork\AddContractPhoto;
use App\Actions\RecordCharge;
use App\Domain\Contracts\SerialCheck;
use App\Domain\Investors\MainInvestor;
use App\Domain\LateFees\LateFeePolicy;
use App\Domain\Ledger\LedgerLines;
use App\Domain\Ledger\OpenAccount;
use App\Domain\PayPattern\PayPattern;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\ApiException;
use App\Http\Requests\CancelContractRequest;
use App\Http\Requests\ChargeRequest;
use App\Http\Requests\ContractRequest;
use App\Models\Contract;
use App\Models\ContractConversion;
use App\Models\Customer;
use App\Models\CustomerPayPattern;
use App\Models\Guarantor;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\LateFee;
use App\Models\Product;
use App\Models\StoredFile;
use App\Models\Transaction;
use App\Reports\ContractProgress;
use App\Support\Files;
use App\Support\Format;
use App\Support\Money;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
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
            // Daily to yearly plans, the shop's own dates, up to 600 and grace days; otherwise the basic three, up to 120.
            'flexible' => Entitlements::for($this->current->get())->check(Feature::FlexibleSchedules)->enabled(),
            'investors' => $this->funders(),
            'open' => Entitlements::for($this->current->get())->check(Feature::OpenContracts)->enabled(),
            // What was sold, its cost, the tax, a discount and the shop's own number (Win Plan PP7).
            'details' => Entitlements::for($this->current->get())->check(Feature::ContractItems)->enabled(),
            'products' => Product::query()->active()->orderByRaw('LOWER(name)')->get(['id', 'name', 'default_price', 'cost']),
            'currency' => $this->current->get()?->currency,
            // The contract date offered: the workspace's today, not the server's (UTC).
            'today' => WorkspaceDay::of($this->current->get())->date,
            // The day each of those customers usually pays on, for the suggestion under the first due date.
            'payPatterns' => $this->payPatterns($customers),
        ]);
    }

    /**
     * What payday learning knows about the customers in the form's list (daily-habit wave A, H6), by customer id: the day
     * they usually pay on, whether that means the end of the month, and their name as the list shows it (a name is not
     * "the first word": "عبد الله", "Mr. Ahmad", "Mobile Zone"). Read in one query for all of them, and empty while the
     * feature is off or when nobody has a pattern yet, so the form then carries nothing.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<string, array{day: int, end_of_month: bool, name: string}>
     */
    private function payPatterns(Collection $customers): array
    {
        if ($customers->isEmpty() || ! Entitlements::for($this->current->get())->check(Feature::PaydayLearning)->enabled()) {
            return [];
        }

        // One query for the customers in this form's own list, and no others.
        $stored = CustomerPayPattern::query()->whereIn('customer_id', $customers->modelKeys())->get(['customer_id', 'day'])->keyBy('customer_id');

        $patterns = [];
        foreach ($customers as $customer) {
            $pattern = $stored->get($customer->id);
            if ($pattern !== null) {
                $patterns[$customer->id] = [
                    'day' => $pattern->day,
                    'end_of_month' => $pattern->day === PayPattern::END_OF_MONTH,
                    'name' => $customer->name,
                ];
            }
        }

        return $patterns;
    }

    /**
     * Who may fund a new contract, for "Funded by": asked of people who see the investors, while the feature is on, and
     * only worth asking once the business has partners (the form shows it with two or more).
     *
     * @return Collection<int, Investor>
     */
    private function funders(): Collection
    {
        $tenant = $this->current->get();
        if ($tenant === null || ! Gate::allows('viewAny', Investor::class) || ! Entitlements::for($tenant)->check(Feature::Investors)->enabled()) {
            return new Collection;
        }

        MainInvestor::for($tenant);

        return Investor::query()->active()->orderByDesc('is_main')->orderByRaw('LOWER(name)')->get();
    }

    public function store(ContractRequest $request, CreateContract $create): RedirectResponse
    {
        $contract = $create->handle($this->current->get(), $request->validated(), $request->user());
        // A serial already on another running contract never stops the sale, but the person at the counter sees it.
        $warnings = array_column(SerialCheck::warnings($contract), 'message');

        return redirect()->route('app.contracts.show', $contract)
            ->with('status', __('Contract :reference opened.', ['reference' => $contract->reference()]))
            ->with($warnings === [] ? [] : ['warning' => implode(' ', $warnings)]);
    }

    public function show(Contract $contract): View
    {
        Gate::authorize('view', $contract);

        if ($contract->isOpen()) {
            return $this->showOpen($contract);
        }

        $installments = Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get();
        $lines = LedgerLines::withReversals(Transaction::query()->where('contract_id', $contract->id)->with('createdBy')
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->get());

        // Late fees (Win Plan K4) are owed with the instalments, and listed on their own.
        $lateFees = LateFee::query()->where('contract_id', $contract->id)->with(['installment', 'waivedBy'])->orderBy('charged_on')->orderBy('period')->get();
        $feesOwed = $contract->status === 'cancelled' ? '0' : $lateFees->reduce(fn (string $carry, LateFee $fee) => Money::add($carry, $fee->remaining()), '0');
        $owed = $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->remaining()), $feesOwed);
        $paid = $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->paid_amount), '0');
        // The live schedule, in date order: superseded instalments (a plan change, Win Plan PP6) live in the history.
        $live = $installments->reject(fn (Installment $i) => $i->status === 'superseded')->sortBy(fn (Installment $i) => [$i->due_date->format('Y-m-d'), $i->number])->values();
        $next = $live->first(fn (Installment $i) => ! in_array($i->status, Installment::CLOSED, true));
        $tenant = $this->current->get();
        $features = Entitlements::for($tenant);
        $scheduled = $contract->status === 'active' && $contract->type === 'scheduled';

        return view('app.contracts.show', [
            'contract' => $contract->load(['customer', 'investor', 'items', 'adjustments', 'amendments.approvedBy']),
            'installments' => $live,
            // Win Plan PP6: what the owner may do to the plan, and which instalment a payment covers.
            'canSettle' => $scheduled && Gate::allows('settle', $contract) && $features->check(Feature::EarlySettlement)->enabled(),
            'canAmend' => $scheduled && Gate::allows('amend', $contract) && $features->check(Feature::Reschedule)->enabled(),
            'canTarget' => $features->check(Feature::PaymentTargeting)->enabled(),
            'paidAheadUntil' => $this->paidAheadUntil($contract, $live),
            'lines' => $lines,
            'lateFees' => $lateFees,
            'feesOwed' => $feesOwed,
            'lateFeePolicy' => LateFeePolicy::fromArray($contract->late_fee_policy),
            // Which payments have been reversed already: they keep their line but can no longer be voided.
            'reversed' => $lines->pluck('reverses_transaction_id')->filter()->flip()->all(),
            'owed' => $contract->status === 'cancelled' ? '0' : $owed,
            'paid' => $paid,
            'next' => $next,
            'currency' => $this->current->get()?->currency,
            // What becoming open would do, shown before the owner confirms it (Win Plan PP4).
            'conversion' => $contract->status === 'active' && Gate::allows('convert', $contract)
                && Entitlements::for($this->current->get())->check(Feature::OpenContracts)->enabled()
                ? app(ConvertToOpen::class)->handle($contract, preview: true) : null,
            ...$this->paperwork($contract),
        ]);
    }

    /**
     * The guarantors and photos (Win Plan K5), with what this person may do with them. Photos a role may not open (an
     * ID for a collector) are left out; ordinary ones come with a five-minute link, ID ones are opened one at a time.
     *
     * @return array<string, mixed>
     */
    private function paperwork(Contract $contract): array
    {
        $role = auth()->user()?->roleIn($contract->tenant_id);
        $on = Entitlements::for($this->current->get())->check(Feature::Guarantors)->enabled();
        $guarantors = $contract->guarantors()->get();
        $all = $contract->photos()->get();
        $photos = $all->filter(fn (StoredFile $photo) => $role !== null && $photo->kind->openedBy($role))->values();
        $files = app(Files::class);

        return [
            'guarantors' => $guarantors,
            'customerSignature' => $contract->signature()->first(),
            'photos' => $photos,
            'photoLinks' => $photos->reject(fn (StoredFile $photo) => $photo->kind->audited())
                ->mapWithKeys(fn (StoredFile $photo) => [$photo->id => $files->temporaryUrl($photo)])->all(),
            'paperwork' => [
                'shown' => $on || $guarantors->isNotEmpty() || $photos->isNotEmpty() || $contract->signature()->exists(),
                'promissoryNote' => Entitlements::for($this->current->get())->check(Feature::PromissoryNote)->enabled() && Gate::allows('addPaperwork', $contract),
                'canAdd' => $on && Gate::allows('addPaperwork', $contract),
                'canRemove' => Gate::allows('removePaperwork', $contract),
                'seesIds' => $role?->seesIdentities() ?? false,
                'roomForGuarantors' => $guarantors->count() < Guarantor::MAX_PER_CONTRACT,
                'roomForPhotos' => $all->count() < AddContractPhoto::MAX_PER_CONTRACT,
            ],
        ];
    }

    /**
     * The due date the instalments are paid up to, when it is still ahead of today (Win Plan PP6).
     *
     * @param  \Illuminate\Support\Collection<int, Installment>  $live
     */
    private function paidAheadUntil(Contract $contract, $live): ?CarbonInterface
    {
        $last = null;
        foreach ($live as $installment) {
            if ($installment->status !== 'paid') {
                break;
            }
            $last = $installment;
        }

        return $contract->status === 'active' && $last !== null && $last->due_date->isAfter(today()) ? $last->due_date : null;
    }

    /** An open contract's page: the balance, "they took" and "they paid", and the account with the balance after each line. */
    private function showOpen(Contract $contract): View
    {
        $lines = LedgerLines::withReversals(Transaction::query()->where('contract_id', $contract->id)->with('createdBy')
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->get());
        $balances = OpenAccount::runningBalances($contract);
        $counted = $lines->filter(fn (Transaction $line) => isset($balances[$line->id]));

        return view('app.contracts.open', [
            'contract' => $contract->load(['customer', 'investor', 'items']),
            'lines' => $lines,
            'balances' => $balances,
            'reversed' => $lines->pluck('reverses_transaction_id')->filter()->flip()->all(),
            'balance' => OpenAccount::balance($contract),
            'took' => $counted->whereIn('type', Transaction::CHARGES)->reduce(fn (string $sum, Transaction $l) => Money::add($sum, $l->amount, 2), '0.00'),
            'paid' => $counted->whereNotIn('type', Transaction::CHARGES)->reduce(fn (string $sum, Transaction $l) => Money::add($sum, $l->amount, 2), '0.00'),
            'installments' => Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get(),
            'conversion' => ContractConversion::query()->where('contract_id', $contract->id)->first(),
            'canCharge' => Entitlements::for($this->current->get())->check(Feature::OpenContracts)->enabled(),
            'currency' => $this->current->get()?->currency,
            ...$this->paperwork($contract),
        ]);
    }

    /** "They took", from the open contract's page; past the credit limit it still records, and says so. */
    public function charge(ChargeRequest $request, Contract $contract, RecordCharge $record): RedirectResponse
    {
        Gate::authorize('view', $contract);
        Gate::authorize('create', Transaction::class);
        $data = $request->validated();
        $currency = $this->current->get()->currency ?? '';

        $result = $record->handle($contract, $data['amount'], $data['tag'] ?? null, $data['note'] ?? null, $data['idempotency_key'] ?? null, $request->user(), $request->chargedAt());

        $redirect = redirect()->route('app.contracts.show', $contract)
            ->with('status', __(':amount added to the balance.', ['amount' => Format::money($result['transaction']->amount, $currency)]));

        return $result['over_credit_limit']
            ? $redirect->with('warning', __('The balance is now :balance, past the credit limit of :limit.', ['balance' => Format::money($result['balance'], $currency), 'limit' => Format::money((string) $contract->credit_limit, $currency)]))
            : $redirect;
    }

    public function convert(Request $request, Contract $contract, ConvertToOpen $convert): RedirectResponse
    {
        Gate::authorize('convert', $contract);

        $outcome = $convert->handle($contract, $request->user());

        return redirect()->route('app.contracts.show', $contract)->with('status', __('It is an open contract now. :amount came over as its opening balance.', [
            'amount' => Format::money($outcome['opening_balance'], $this->current->get()->currency ?? ''),
        ]));
    }

    public function cancel(CancelContractRequest $request, Contract $contract, CancelContract $cancel): RedirectResponse
    {
        $cancel->handle($contract, $request->reason(), $request->user());

        return redirect()->route('app.contracts.show', $contract)
            ->with('status', __('Contract :reference cancelled. Its history is kept.', ['reference' => $contract->reference()]));
    }

    /** Puts a settled or cancelled contract away (Win Plan PP12), or brings it back. */
    public function archive(Request $request, Contract $contract, ArchiveContract $archive): RedirectResponse
    {
        Gate::authorize('archive', $contract);
        try {
            $archive->handle($contract, true, $request->user());
        } catch (ApiException $e) {
            return back()->withErrors(['contract' => $e->getMessage()]);
        }

        return back()->with('status', __('Contract :reference archived. It is under Archived in your contracts.', ['reference' => $contract->reference()]));
    }

    public function unarchive(Request $request, Contract $contract, ArchiveContract $archive): RedirectResponse
    {
        Gate::authorize('archive', $contract);
        $archive->handle($contract, false, $request->user());

        return back()->with('status', __('Contract :reference is back in your lists.', ['reference' => $contract->reference()]));
    }
}
