<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Domain\Ledger\Statement;
use App\Models\Contract;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A customer's account: every contract with what is owed and late today, then every line of the period with a running
 * balance (or one contract only, when asked).
 */
final class CustomerStatement implements Template
{
    private string $reference = '';

    private ?string $owed = null;

    public function __construct(private readonly Customer $customer) {}

    public function kind(): string
    {
        return 'customer_statement';
    }

    public function subject(): Model
    {
        return $this->customer;
    }

    public function view(): string
    {
        return 'documents.customer-statement';
    }

    public function data(DocumentOptions $options): array
    {
        $contracts = Contract::query()->where('customer_id', $this->customer->id)
            ->when($options->contract !== null, fn ($query) => $query->whereKey($options->contract))
            ->with(['installments', 'items'])->orderBy('number')->get();

        $balances = Statement::owedAndOverdue($contracts);
        $rows = [];
        $owed = '0.00';
        $overdue = '0.00';
        foreach ($contracts as $contract) {
            $rows[] = [
                'contract' => $contract,
                'sold' => $contract->isOpen() ? null : Money::add($contract->total, $contract->down_payment, 2),
                'owed' => $balances[$contract->id]['owed'],
                'overdue' => $balances[$contract->id]['overdue'],
            ];
            $owed = Money::add($owed, $balances[$contract->id]['owed'], 2);
            $overdue = Money::add($overdue, $balances[$contract->id]['overdue'], 2);
        }

        $references = $contracts->map(fn (Contract $c) => $c->reference());
        $this->reference = $references->take(3)->implode(', ').($references->count() > 3 ? ' +'.($references->count() - 3) : '');
        $this->owed = $owed;

        return [
            'customer' => $this->customer,
            'contracts' => $rows,
            'statement' => Statement::of($contracts, $options->from, $options->to),
            'owed' => $owed,
            'overdue' => $overdue,
        ];
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function total(): ?string
    {
        return $this->owed;
    }

    public function filename(DocumentOptions $options): string
    {
        $name = Str::slug($this->customer->name, language: 'en');

        return 'statement'.($name === '' ? '' : '-'.$name).'-'.today()->format('Y-m-d').'.pdf';
    }
}
