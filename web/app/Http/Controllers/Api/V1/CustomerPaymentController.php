<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordCustomerPayment;
use App\Domain\Ledger\LedgerLines;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Resources\TransactionResource;
use App\Models\Customer;
use App\Models\Transaction;
use App\Support\Digits;
use App\Support\PaymentDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * One payment across a customer's contracts (Win Plan PP6): the oldest instalment due first, or split by hand. Safe to
 * retry with an Idempotency-Key: the same key answers with the same batch (200 instead of 201).
 */
final class CustomerPaymentController
{
    public function __invoke(Request $request, Customer $customer, RecordCustomerPayment $record): JsonResponse
    {
        Gate::authorize('view', $customer);
        Gate::authorize('create', Transaction::class);
        Entitlements::for($customer->tenant)->assertEnabled(Feature::PaymentTargeting);

        $money = ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'];
        $request->merge(['amount' => is_scalar($request->input('amount')) ? Digits::toAscii(trim((string) $request->input('amount'))) : null]);
        $data = $request->validate([
            'amount' => $money,
            'method' => ['required', 'string', Rule::in(config('qistas.payment_methods'))],
            'paid_at' => ['nullable', 'bail', 'date', PaymentDate::notInFuture($customer->tenant)],
            'note' => ['nullable', 'string', 'max:1000'],
            'split' => ['nullable', 'array', 'min:1', 'max:50'],
            'split.*.contract_id' => ['required', 'uuid', 'distinct'],
            'split.*.amount' => $money,
        ]);
        $paidAt = isset($data['paid_at']) ? PaymentDate::moment($customer->tenant, $data['paid_at']) : null;

        $batch = $record->handle(
            $customer, $data['amount'], $data['method'], trim((string) $request->header('Idempotency-Key')) ?: null,
            $request->user(), $data['note'] ?? null, $paidAt, $data['split'] ?? null,
        );

        $payments = Transaction::query()->where('batch_id', $batch->id)->with(['createdBy', 'customer', 'contract'])->orderBy('created_at')->orderBy('id')->get();
        LedgerLines::withReversals($payments);

        return response()->json(['data' => [
            'id' => $batch->id,
            'amount' => bcadd((string) $batch->amount, '0', 2),
            'method' => $batch->method,
            'payments' => TransactionResource::collection($payments)->resolve($request),
        ]], $batch->wasRecentlyCreated ? 201 : 200);
    }
}
