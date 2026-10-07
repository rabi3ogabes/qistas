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
use App\Reports\CustomerBalances;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $customers = Customer::query()->search($term)->orderByRaw('LOWER(name)')->paginate(20)->withQueryString();

        return view('app.customers.index', [
            'customers' => $customers,
            'balances' => $balances->forCustomers($customers->pluck('id')->all()),
            'term' => $term,
            'total' => Customer::query()->count(),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Customer::class);

        return view('app.customers.create', ['usage' => $this->usage(), 'customer' => new Customer]);
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
            'customer' => $customer,
            'contracts' => $contracts,
            'owed' => $balances->forContracts($contracts->pluck('id')->all()),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('app.customers.edit', ['customer' => $customer]);
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

        $customer->update($data);

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
}
