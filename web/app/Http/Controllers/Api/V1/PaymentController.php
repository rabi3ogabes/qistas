<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\VoidPaymentRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Contract;
use App\Models\Transaction;
use App\Reports\ContractProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Money in: the ledger, taking a payment (safe to retry with an Idempotency-Key) and voiding one. */
final class PaymentController
{
    public function __construct(private readonly ContractProgress $progress) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Transaction::class);

        $contract = $request->query('contract_id');

        // Money that came in; what an open contract's customer took is owed, not paid, and lives on the contract.
        $page = Transaction::query()->moneyIn()->with(['customer', 'contract', 'createdBy'])
            ->when(is_string($contract) && $contract !== '', fn ($query) => $query->where('contract_id', $contract))
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(PerPage::of($request))->withQueryString();

        $reversed = Transaction::query()->whereIn('reverses_transaction_id', $page->pluck('id'))->pluck('reverses_transaction_id')->flip();
        $page->getCollection()->each(fn (Transaction $line) => $line->setAttribute('voided', $line->type === 'payment' && $reversed->has($line->id)));

        return TransactionResource::collection($page);
    }

    public function store(PaymentRequest $request, Contract $contract, RecordPayment $record): JsonResponse
    {
        $data = $request->validated();

        $payment = $record->handle(
            $contract, $data['amount'], $data['method'], $data['idempotency_key'] ?? null,
            $request->user(), $data['note'] ?? null, $request->paidAt(), $data['tag'] ?? null,
        );

        // A retry that finds the first attempt answers with it, and says so: 200 instead of 201.
        return $this->show($payment)->response()->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
    }

    public function void(VoidPaymentRequest $request, Transaction $transaction, VoidTransaction $void): JsonResponse
    {
        return $this->show($void->handle($transaction, $request->reason(), $request->user()))->response()->setStatusCode(200);
    }

    private function show(Transaction $transaction): TransactionResource
    {
        $transaction->load(['createdBy', 'customer', 'contract']);
        $transaction->contract->setAttribute('owed', $this->progress->forContracts([$transaction->contract_id])[$transaction->contract_id]['owed']);
        $transaction->setAttribute('voided', in_array($transaction->type, ['payment', 'charge'], true) && Transaction::where('reverses_transaction_id', $transaction->id)->exists());

        return new TransactionResource($transaction);
    }
}
