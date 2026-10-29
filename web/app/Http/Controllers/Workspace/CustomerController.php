<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\CreateCustomer;
use App\Actions\DeleteCustomer;
use App\Domain\Ledger\ContractAccount;
use App\Domain\Ledger\Statement;
use App\Domain\PayPattern\PayPatternWords;
use App\Entitlements\Entitlement;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureUsage;
use App\Http\Requests\CustomerRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Tag;
use App\Models\Transaction;
use App\Reports\CustomerBalances;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The workspace's customers. Thin: the rules live in the actions, the policy and the request. */
final class CustomerController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request, CustomerBalances $balances): View
    {
        Gate::authorize('viewAny', Customer::class);

        $term = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), Customer::SORTS, true) ? (string) $request->query('sort') : 'name';
        $tagsOn = Entitlements::for($this->current->get())->check(Feature::CustomerTags)->enabled();
        $tags = $tagsOn ? Tag::query()->orderByRaw('LOWER(name)')->get() : collect();
        $tag = $tags->firstWhere('id', (string) $request->query('tag'));

        $customers = Customer::query()->with('tags')->search($term)
            ->when($tag !== null, fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereKey($tag->id)))
            ->sorted($sort)->paginate(20)->withQueryString();

        return view('app.customers.index', [
            'customers' => $customers,
            'sort' => $sort,
            'tags' => $tags,
            'tag' => $tag,
            'balances' => $balances->forCustomers($customers->pluck('id')->all()),
            'term' => $term,
            'total' => Customer::query()->count(),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Customer::class);

        return view('app.customers.create', ['usage' => $this->usage(), 'customer' => new Customer, 'tags' => $this->tags()]);
    }

    public function store(CustomerRequest $request, CreateCustomer $create): RedirectResponse
    {
        $customer = $create->handle($this->current->get(), $request->validated(), $request->user());

        return redirect()->route('app.customers.show', $customer)->with('status', __('Customer added.'));
    }

    /**
     * The customer and one contract's account on one page: its lines with a running balance, what is unpaid and late
     * with a way to pay it, and the totals. ?contract= picks the contract; ?pay= opens the payment form, for an
     * instalment by its id or for the next one due.
     */
    public function show(Request $request, Customer $customer): View
    {
        Gate::authorize('view', $customer);

        $contracts = Contract::query()->where('customer_id', $customer->id)->with(['installments', 'investor'])->orderByDesc('number')->get();
        $standing = Statement::owedAndOverdue($contracts);
        $chosen = $this->chosenContract($contracts, $standing, $request->query('contract'));
        $account = $chosen === null ? null : ContractAccount::of($chosen);
        $pay = is_string($request->query('pay')) && $request->query('pay') !== '' ? (string) $request->query('pay') : null;
        $canPay = $account !== null && $account->takesPayments() && Gate::allows('create', Transaction::class);
        $features = Entitlements::for($this->current->get());

        // The day they usually pay on (daily-habit wave A, H6), while payday learning is on. A pattern worked out earlier
        // stays in the database when it is off, and is not read or shown.
        $pattern = $features->check(Feature::PaydayLearning)->enabled() ? $customer->payPattern : null;
        if ($pattern !== null) {
            // The cockpit's "used by" count: a pattern really shown to someone is this feature's primary action.
            FeatureUsage::hit(Feature::PaydayLearning);
        }

        return view('app.customers.show', [
            'customer' => $customer->load('tags'),
            'payPattern' => $pattern === null ? null : ['label' => PayPatternWords::label($pattern->day), 'basis' => PayPatternWords::basis($pattern->within)],
            'contracts' => $contracts,
            'owed' => array_map(fn (array $s) => $s['owed'], $standing),
            'overdue' => array_map(fn (array $s) => $s['overdue'], $standing),
            'chosen' => $chosen,
            'account' => $account,
            'canPay' => $canPay,
            'payOpen' => $canPay && ($pay !== null || session()->hasOldInput('return_to')),
            'payFor' => $canPay ? ($account->openInstallment($pay) ?? $account->next) : null,
            'canTarget' => $features->check(Feature::PaymentTargeting)->enabled(),
            'canArchive' => $chosen !== null && $chosen->archived_at === null && in_array($chosen->status, ['settled', 'cancelled'], true) && Gate::allows('archive', $chosen),
            'currency' => $this->current->get()?->currency,
            // One payment across their contracts (Win Plan PP6), once two or more are running.
            'canPayAcross' => $contracts->where('status', 'active')->where('type', '!=', 'open')->count() > 1
                && Gate::allows('create', Transaction::class)
                && $features->check(Feature::PaymentTargeting)->enabled(),
        ]);
    }

    /**
     * The contract asked for if it is this customer's; otherwise the one that needs attention: late, then running,
     * then the newest. An archived contract stays folded away until it is asked for.
     *
     * @param  Collection<int, Contract>  $contracts  newest first
     * @param  array<string, array{owed: string, overdue: string}>  $standing
     */
    private function chosenContract(Collection $contracts, array $standing, mixed $asked): ?Contract
    {
        if (is_string($asked) && ($picked = $contracts->firstWhere('id', $asked)) !== null) {
            return $picked;
        }

        return $contracts->whereNull('archived_at')
            ->sortBy(fn (Contract $c) => [Money::isPositive($standing[$c->id]['overdue']) ? 0 : 1, $c->status === 'active' ? 0 : 1, -$c->number])
            ->first();
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('app.customers.edit', ['customer' => $customer->load('tags'), 'tags' => $this->tags()]);
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $data = $request->validated();
        $remove = (bool) ($data['remove_national_id'] ?? false);
        unset($data['remove_national_id']);

        if ($remove) {
            $data['national_id'] = null;
        } elseif (($data['national_id'] ?? null) === null) {
            unset($data['national_id']); // blank means "keep the stored ID", not "erase it"
        }

        // Tags only change when they were sent; sent empty, they are cleared.
        $sentTags = array_key_exists('tags', $data);
        $tags = $data['tags'] ?? [];
        unset($data['tags']);
        $customer->update($data);
        if ($sentTags) {
            Entitlements::for($this->current->get())->assertEnabled(Feature::CustomerTags);
            $customer->syncTags($tags);
        }

        return redirect()->route('app.customers.show', $customer)->with('status', __('Customer saved.'));
    }

    public function destroy(Customer $customer, DeleteCustomer $delete): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $delete->handle($customer);

        return redirect()->route('app.customers.index')->with('status', __('Customer deleted.'));
    }

    /** The customer limit, for the form's usage line and the "limit reached" prompt. */
    private function usage(): Entitlement
    {
        return Entitlements::for($this->current->get())->check(Feature::Customers);
    }

    /** Keeps a regular at the top of every list (Win Plan PP12). */
    public function pin(Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $customer->forceFill(['pinned_at' => now()])->save();

        return back()->with('status', __(':name is pinned to the top of your lists.', ['name' => $customer->name]));
    }

    public function unpin(Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $customer->forceFill(['pinned_at' => null])->save();

        return back()->with('status', __(':name is no longer pinned.', ['name' => $customer->name]));
    }

    /**
     * The business's tags to choose from on the form, while the feature is on (Win Plan PP12).
     *
     * @return Collection<int, Tag>
     */
    private function tags(): Collection
    {
        return Entitlements::for($this->current->get())->check(Feature::CustomerTags)->enabled()
            ? Tag::query()->orderByRaw('LOWER(name)')->get()
            : collect();
    }
}
