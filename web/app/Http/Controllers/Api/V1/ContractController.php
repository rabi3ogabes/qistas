<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelContract;
use App\Actions\CreateContract;
use App\Http\Requests\CancelContractRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Transaction;
use App\Reports\ContractProgress;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/** The workspace's contracts. Thin: the rules live in the actions, the policy and the requests. */
final class ContractController
{
    public function __construct(private readonly CurrentTenant $current, private readonly ContractProgress $progress) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Contract::class);

        $status = $request->query('status');
        $view = is_string($status) && in_array($status, Contract::VIEWS, true) ? $status : 'active';

        $page = Contract::query()->with('customer')->inView($view)->search((string) $request->query('q', ''))
            ->orderByDesc('number')->paginate(PerPage::of($request))->withQueryString();

        $this->attachProgress($page->getCollection());

        return ContractResource::collection($page);
    }

    public function store(ContractRequest $request, CreateContract $create): JsonResponse
    {
        $contract = $create->handle($this->current->get(), $request->validated(), $request->user());

        return $this->one($contract)->response()->setStatusCode(201);
    }

    public function show(Contract $contract): ContractResource
    {
        Gate::authorize('view', $contract);

        return $this->one($contract);
    }

    public function cancel(CancelContractRequest $request, Contract $contract, CancelContract $cancel): ContractResource
    {
        $cancel->handle($contract, $request->reason(), $request->user());

        return $this->one($contract->refresh());
    }

    /** One contract with everything a screen needs: customer, schedule, money received, what is owed. */
    private function one(Contract $contract): ContractResource
    {
        $installments = Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get();
        $lines = Transaction::query()->where('contract_id', $contract->id)->with('createdBy')
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->get();
        $reversed = $lines->pluck('reverses_transaction_id')->filter()->flip();
        $lines->each(fn (Transaction $line) => $line->setAttribute('voided', $line->type === 'payment' && $reversed->has($line->id)));

        $contract->load(['customer', 'investor']);
        $this->attachProgress(collect([$contract]));
        $contract->setAttribute('paid', $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->paid_amount), '0'));
        $contract->setRelation('installments', $installments);
        $contract->setRelation('transactions', $lines);

        return new ContractResource($contract);
    }

    /** @param  Collection<int, Contract>  $contracts */
    private function attachProgress($contracts): void
    {
        $details = $this->progress->forContracts($contracts->pluck('id')->all());

        $contracts->each(fn (Contract $contract) => $contract->setAttribute('progress', $details[$contract->id]));
    }
}
