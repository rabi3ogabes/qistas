<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\CreateCustomer;
use App\Actions\DeleteCustomer;
use App\Entitlements\Entitlement;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\CustomerRequest;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Tag;
use App\Reports\CustomerBalances;
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

    public function show(Customer $customer, CustomerBalances $balances): View
    {
        Gate::authorize('view', $customer);

        $contracts = Contract::query()->where('customer_id', $customer->id)->orderByDesc('number')->get();

        return view('app.customers.show', [
            'customer' => $customer->load('tags'),
            'contracts' => $contracts,
            'owed' => $balances->forContracts($contracts->pluck('id')->all()),
            'currency' => $this->current->get()?->currency,
        ]);
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
