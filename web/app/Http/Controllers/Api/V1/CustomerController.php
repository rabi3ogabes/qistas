<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateCustomer;
use App\Actions\DeleteCustomer;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
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
use Illuminate\Validation\Rule;

/** The workspace's customers. Thin: the rules live in the actions, the policy and the request. */
final class CustomerController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request, CustomerBalances $balances): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $filters = $request->validate(['sort' => ['nullable', Rule::in(Customer::SORTS)], 'tag' => ['nullable', 'uuid']]);

        $page = Customer::query()->with('tags')->search((string) $request->query('q', ''))
            ->when(! empty($filters['tag']), fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereKey($filters['tag'])))
            ->sorted($filters['sort'] ?? 'name')
            ->paginate(PerPage::of($request))->withQueryString();
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

        return (new CustomerResource($customer->load('tags')))->response()->setStatusCode(201);
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

        return new CustomerResource($customer->load('tags'));
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

        // Tags only change when they were sent; sent empty, they are cleared.
        $sentTags = array_key_exists('tags', $data);
        $tags = $data['tags'] ?? [];
        unset($data['tags']);
        $customer->update($data);
        if ($sentTags) {
            Entitlements::for($this->current->get())->assertEnabled(Feature::CustomerTags);
            $customer->syncTags($tags);
        }

        return new CustomerResource($customer->refresh()->load('tags'));
    }

    public function destroy(Customer $customer, DeleteCustomer $delete): Response
    {
        Gate::authorize('delete', $customer);

        $delete->handle($customer);

        return response()->noContent();
    }

    /** Keeps a regular at the top of every list (Win Plan PP12). */
    public function pin(Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);
        $customer->forceFill(['pinned_at' => now()])->save();

        return new CustomerResource($customer->load('tags'));
    }

    public function unpin(Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);
        $customer->forceFill(['pinned_at' => null])->save();

        return new CustomerResource($customer->load('tags'));
    }
}
