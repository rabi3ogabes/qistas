<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Models\Contract;
use App\Models\Installment;
use App\Support\AmountInWords;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A promissory note (سند لأمر) for what the customer still owes on a contract's instalments (Win Plan K5, CT23): the
 * amount in figures and in words, payable on the last instalment's due date, to the order of the shop, with the
 * guarantors under it and the customer's signature when there is one. Its wording is a draft until a lawyer approves
 * it, which is why it sits behind its own switch. Late fees are not part of it.
 */
final class PromissoryNote implements Template
{
    private string $amount = '0.00';

    public function __construct(private readonly Contract $contract) {}

    public function kind(): string
    {
        return 'promissory_note';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function view(): string
    {
        return 'documents.promissory-note';
    }

    public function data(DocumentOptions $options): array
    {
        $contract = $this->contract->loadMissing(['customer', 'guarantors', 'signature', 'installments' => fn ($query) => $query->orderBy('number')]);
        $live = $contract->installments->reject(fn (Installment $installment) => $installment->status === 'superseded');
        $this->amount = $live->reduce(fn (string $sum, Installment $installment) => Money::add($sum, $installment->remaining(), 2), '0.00');
        $due = $live->sortBy(fn (Installment $installment) => $installment->due_date->format('Y-m-d'))->last()->due_date ?? $contract->start_date;

        return [
            'contract' => $contract,
            'customer' => $contract->customer,
            'guarantors' => $contract->guarantors,
            'customerSignature' => $contract->signature,
            'amount' => $this->amount,
            'inWords' => AmountInWords::say($this->amount, (string) $contract->tenant->currency, $options->language),
            'due' => $due,
        ];
    }

    public function reference(): string
    {
        return $this->contract->reference();
    }

    public function total(): string
    {
        return $this->amount;
    }

    public function filename(DocumentOptions $options): string
    {
        return 'promissory-note-'.Str::slug($this->contract->reference(), language: 'en').'.pdf';
    }
}
