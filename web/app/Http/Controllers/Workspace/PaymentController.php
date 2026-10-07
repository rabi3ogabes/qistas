<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\VoidPaymentRequest;
use App\Models\Contract;
use App\Models\Transaction;
use App\Support\Format;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Money in: the workspace's ledger, taking a payment, and voiding one. Thin: RecordPayment and VoidTransaction decide. */
final class PaymentController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Transaction::class);

        $lines = Transaction::query()->with(['customer', 'contract', 'createdBy'])
            ->orderByDesc('paid_at')->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return view('app.payments.index', [
            'lines' => $lines,
            'reversed' => Transaction::query()->whereIn('reverses_transaction_id', $lines->pluck('id'))->pluck('reverses_transaction_id')->flip()->all(),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function store(PaymentRequest $request, Contract $contract, RecordPayment $record): RedirectResponse
    {
        $data = $request->validated();

        $payment = $record->handle(
            $contract, $data['amount'], $data['method'], $data['idempotency_key'] ?? null,
            $request->user(), $data['note'] ?? null, $request->paidAt(),
        );

        return redirect()->route('app.contracts.show', $contract)
            ->with('status', __('Payment of :amount recorded.', ['amount' => Format::money($payment->amount, $this->current->get()->currency)]));
    }

    public function void(VoidPaymentRequest $request, Transaction $transaction, VoidTransaction $void): RedirectResponse
    {
        $void->handle($transaction, $request->reason(), $request->user());

        return redirect()->route('app.contracts.show', $transaction->contract_id)
            ->with('status', __('Payment voided. The original line stays in the history.'));
    }
}
