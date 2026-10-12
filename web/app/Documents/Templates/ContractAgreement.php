<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The contract as it was agreed (Win Plan K5, CT23): the shop and the customer, the guarantors, what was sold, the
 * price and the plan, the schedule, and the customer's signature when there is one. ID numbers are printed masked, and
 * only for those who see them. Carries the verification QR like every document.
 */
final class ContractAgreement implements Template
{
    public function __construct(private readonly Contract $contract) {}

    public function kind(): string
    {
        return 'contract_agreement';
    }

    public function subject(): Model
    {
        return $this->contract;
    }

    public function view(): string
    {
        return 'documents.contract-agreement';
    }

    public function data(DocumentOptions $options): array
    {
        $contract = $this->contract->loadMissing(['customer', 'items', 'guarantors', 'signature', 'installments' => fn ($query) => $query->orderBy('number')]);

        return [
            'contract' => $contract,
            'customer' => $contract->customer,
            'guarantors' => $contract->guarantors,
            'customerSignature' => $contract->signature,
            // The schedule as agreed: instalments set aside by a later plan change are not part of it.
            'schedule' => $contract->installments->reject(fn (Installment $installment) => $installment->status === 'superseded')->values(),
            'sold' => Money::add($contract->total, $contract->down_payment, 2),
        ];
    }

    public function reference(): string
    {
        return $this->contract->reference();
    }

    public function total(): string
    {
        return Money::add($this->contract->total, $this->contract->down_payment, 2);
    }

    public function filename(DocumentOptions $options): string
    {
        return 'contract-'.Str::slug($this->contract->reference(), language: 'en').'.pdf';
    }
}
