<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ArchiveContract;
use App\Actions\CancelContract;
use App\Actions\ConvertToOpen;
use App\Actions\CreateContract;
use App\Actions\RecordCharge;
use App\Domain\Contracts\SerialCheck;
use App\Domain\Ledger\LedgerLines;
use App\Domain\Ledger\OpenAccount;
use App\Http\Requests\CancelContractRequest;
use App\Http\Requests\ChargeRequest;
use App\Http\Requests\ContractRequest;
use App\Http\Resources\ContractResource;
use App\Http\Resources\TransactionResource;
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
        // A serial already on another running contract warns and never refuses (Win Plan PP7).
        $warnings = SerialCheck::warnings($contract);

        return $this->one($contract)->additional($warnings === [] ? [] : ['meta' => ['warnings' => $warnings]])->response()->setStatusCode(201);
    }

    public function show(Contract $contract): ContractResource
    {
        Gate::authorize('view', $contract);

        return $this->one($contract);
    }

    /** Puts a settled or cancelled contract away (Win Plan PP12), or brings it back. */
    public function archive(Request $request, Contract $contract, ArchiveContract $archive): ContractResource
    {
        Gate::authorize('archive', $contract);

        return $this->one($archive->handle($contract, true, $request->user()));
    }

    public function unarchive(Request $request, Contract $contract, ArchiveContract $archive): ContractResource
    {
        Gate::authorize('archive', $contract);

        return $this->one($archive->handle($contract, false, $request->user()));
    }

    public function cancel(CancelContractRequest $request, Contract $contract, CancelContract $cancel): ContractResource
    {
        $cancel->handle($contract, $request->reason(), $request->user());

        return $this->one($contract->refresh());
    }

    /**
     * "They took" on an open contract (Win Plan PP4). The answer says whether the tab is now past its credit limit,
     * which warns and never refuses; a retry with the same key answers with the first line, and 200 instead of 201.
     */
    public function charge(ChargeRequest $request, Contract $contract, RecordCharge $record): JsonResponse
    {
        Gate::authorize('view', $contract);
        Gate::authorize('create', Transaction::class);
        $data = $request->validated();

        $result = $record->handle($contract, $data['amount'], $data['tag'] ?? null, $data['note'] ?? null, $data['idempotency_key'] ?? null, $request->user(), $request->chargedAt());
        $line = $result['transaction']->load('createdBy');

        return (new TransactionResource($line))->additional(['meta' => ['balance' => $result['balance'], 'over_credit_limit' => $result['over_credit_limit']]])
            ->response()->setStatusCode($line->wasRecentlyCreated ? 201 : 200);
    }

    /** Turns a scheduled or cash contract into an open one; with `preview=1`, only says what would happen. */
    public function convert(Request $request, Contract $contract, ConvertToOpen $convert): JsonResponse
    {
        Gate::authorize('convert', $contract);

        if ($request->boolean('preview')) {
            return response()->json(['data' => $convert->handle($contract, $request->user(), preview: true)]);
        }

        $outcome = $convert->handle($contract, $request->user());

        return response()->json(['data' => ['conversion' => $outcome, 'contract' => $this->one($contract->refresh())->resolve($request)]]);
    }

    /** One contract with everything a screen needs: customer, schedule, money received, what is owed. */
    private function one(Contract $contract): ContractResource
    {
        $installments = Installment::query()->where('contract_id', $contract->id)->orderBy('number')->get();
        $lines = Transaction::query()->where('contract_id', $contract->id)->with('createdBy')
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->get();
        $after = $contract->isOpen() ? OpenAccount::runningBalances($contract) : [];
        LedgerLines::withReversals($lines)->each(fn (Transaction $line) => $line->setAttribute('balance_after', $after[$line->id] ?? null));

        $contract->load(['customer', 'investor', 'items']);
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
