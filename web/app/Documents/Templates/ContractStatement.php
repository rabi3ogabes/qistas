<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Domain\Ledger\Statement;
use App\Models\Contract;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One contract: its terms, what was sold, the schedule with what is late, and its account with a running balance. */
final class ContractStatement implements Template
{
    private ?string $owed = null;

    public function __construct(private readonly Contract $contract) {}

    public function kind(): string
    {
        return 'contract_statement';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function view(): string
    {
        return 'documents.contract-statement';
    }

    public function data(DocumentOptions $options): array
    {
        $contract = $this->contract->loadMissing(['customer', 'items', 'installments' => fn ($query) => $query->orderBy('number')]);
        $statement = Statement::of([$contract]);
        ['owed' => $owed, 'overdue' => $overdue] = Statement::owedAndOverdue(collect([$contract]))[$contract->id];
        $this->owed = $owed;

        $schedule = [];
        foreach ($contract->installments as $installment) {
            $late = $contract->status !== 'cancelled' && $installment->isOverdue();
            $schedule[] = [
                'number' => $installment->number,
                'due_date' => $installment->due_date,
                'amount' => Money::add($installment->amount, '0', 2),
                'paid' => Money::add($installment->paid_amount, '0', 2),
                'overdue' => $late ? Money::add($installment->remaining(), '0', 2) : null,
                'state' => $installment->displayState(),
            ];
        }

        $margin = $contract->cost_price === null ? null : Money::sub(Money::add($contract->total, $contract->down_payment, 2), $contract->cost_price, 2);

        return [
            'contract' => $contract,
            'customer' => $contract->customer,
            'statement' => $statement,
            'schedule' => $schedule,
            'owed' => $owed,
            'overdue' => $overdue,
            'paid' => Money::sub(Money::add($contract->total, $contract->down_payment, 2), $owed, 2),
            'margin' => $margin,
        ];
    }

    public function reference(): string
    {
        return $this->contract->reference();
    }

    public function total(): ?string
    {
        return $this->owed;
    }

    public function filename(DocumentOptions $options): string
    {
        return 'statement-'.Str::slug($this->contract->reference(), language: 'en').'.pdf';
    }
}
