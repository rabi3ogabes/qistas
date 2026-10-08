<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateCustomer;
use App\Actions\DeleteCustomer;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Contract;
use App\Models\Customer;
use App\Reports\ContractProgress;
use App\Reports\CustomerBalances;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** The workspace's customers. Thin: the rules live in the actions, the policy and the request. */
final class CustomerController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request, CustomerBalances $balances): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $page = Customer::query()->search((string) $request->query('q', ''))->orderByRaw('LOWER(name)')->paginate(PerPage::of($request))->withQueryString();
        $figures = $balances->forCustomers($page->pluck('id')->all());

        $page->getCollection()->each(function (Customer $customer) use ($figures): void {
            $customer->setAttribute('owed', $figures[$customer->id]['owed']);
            $customer->setAttribute('running_contracts', $figures[$customer->id]['running']);
        });

        return CustomerResource::collection($page);
    }

    public function store(CustomerRequest $request, CreateCustomer $create): JsonResponse
    {
        $customer = $create->handle($this->current->get(), $request->validated(), $request->user());

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function show(Customer $customer, CustomerBalances $balances, ContractProgress $progress): CustomerResource
    {
        Gate::authorize('view', $customer);

        $contracts = Contract::query()->where('customer_id', $customer->id)->orderByDesc('number')->get();
        $details = $progress->forContracts($contracts->pluck('id')->all());
        $contracts->each(fn (Contract $contract) => $contract->setAttribute('progress', $details[$contract->id]));

        $figures = $balances->forCustomers([$customer->id])[$customer->id];
        $customer->setAttribute('owed', $figures['owed']);
        $customer->setAttribute('running_contracts', $figures['running']);
        $customer->setRelation('contracts', $contracts);

        return new CustomerResource($customer);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
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

        return new CustomerResource($customer->refresh());
    }

    public function destroy(Customer $customer, DeleteCustomer $delete): Response
    {
        Gate::authorize('delete', $customer);

        $delete->handle($customer);

        return response()->noContent();
    }
}
