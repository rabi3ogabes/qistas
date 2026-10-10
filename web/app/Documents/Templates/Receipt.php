<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Domain\Ledger\OpenAccount;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A receipt for money that came in: its number (the contract's number and which payment on it this is), the day and the
 * time on the workspace's own clock, what it paid off, and what is still owed after it. A voided payment's receipt says so.
 */
final class Receipt implements Template
{
    private string $number = '';

    public function __construct(private readonly Transaction $payment) {}

    public function kind(): string
    {
        return 'receipt';
    }

    public function subject(): Model
    {
        return $this->payment;
    }

    public function view(): string
    {
        return 'documents.receipt';
    }

    public function data(DocumentOptions $options): array
    {
        $payment = $this->payment->loadMissing(['contract.customer', 'createdBy']);
        $contract = $payment->contract;

        // Which payment on the contract this is: C-0001-2 is the second.
        $order = Transaction::query()->where('contract_id', $contract->id)->whereIn('type', ['payment', 'down_payment'])
            ->orderBy('created_at')->orderBy('id')->pluck('id')->search($payment->id);
        $this->number = $contract->reference().'-'.((int) $order + 1);

        if ($contract->isOpen()) {
            $owedAfter = OpenAccount::runningBalances($contract)[$payment->id] ?? OpenAccount::balance($contract);
        } else {
            $inSoFar = Transaction::query()->where('contract_id', $contract->id)->whereIn('type', Transaction::MONEY_IN)
                ->where(fn ($query) => $query->where('created_at', '<', $payment->created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $payment->created_at)->where('id', '<=', $payment->id)))
                ->get()->reduce(fn (string $sum, Transaction $t) => Money::add($sum, $t->amount, 2), '0.00');
            $owedAfter = Money::sub(Money::add($contract->total, $contract->down_payment, 2), $inSoFar, 2);
        }

        $covered = TransactionAllocation::query()->where('transaction_id', $payment->id)->with('installment')->get()
            ->sortBy(fn (TransactionAllocation $a) => $a->installment->number ?? 0)
            ->map(fn (TransactionAllocation $a) => ['number' => $a->installment->number ?? null, 'amount' => Money::add($a->amount, '0', 2), 'due_date' => $a->installment?->due_date])
            ->values()->all();

        return [
            'payment' => $payment,
            'contract' => $contract,
            'customer' => $contract->customer,
            'number' => $this->number,
            'amount' => Money::add($payment->amount, '0', 2),
            'recordedAt' => $contract->tenant->localTime($payment->created_at),
            'paidOn' => $contract->tenant->localTime($payment->paid_at),
            'covered' => $covered,
            'owedAfter' => $owedAfter,
            'voided' => Transaction::query()->where('reverses_transaction_id', $payment->id)->exists(),
        ];
    }

    public function reference(): string
    {
        return $this->number;
    }

    public function total(): string
    {
        return Money::add($this->payment->amount, '0', 2);
    }

    public function filename(DocumentOptions $options): string
    {
        return 'receipt-'.Str::slug($this->number, language: 'en').'.pdf';
    }
}
