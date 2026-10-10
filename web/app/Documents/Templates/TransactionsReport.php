<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The money that came in over a period (this month so far, unless asked), one line each, with the total for each way of
 * paying and the total of all. Voids are listed and taken off. It can be narrowed to one investor's contracts or to
 * contracts that sold one product.
 */
final class TransactionsReport implements Template
{
    private string $reference = '';

    private ?string $total = null;

    public function kind(): string
    {
        return 'transactions_report';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function view(): string
    {
        return 'documents.transactions-report';
    }

    public function data(DocumentOptions $options): array
    {
        $from = $options->from ?? CarbonImmutable::today()->startOfMonth();
        $to = $options->to ?? CarbonImmutable::today();

        $lines = Transaction::query()->whereIn('type', Transaction::MONEY_IN)
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($options->investor !== null, fn (Builder $query) => $query->whereHas('contract', fn (Builder $contract) => $contract->where('investor_id', $options->investor)))
            ->when($options->product !== null, fn (Builder $query) => $query->whereHas('contract.items', fn (Builder $item) => $item->where('product_id', $options->product)))
            ->with(['contract.customer', 'createdBy'])->orderBy('paid_at')->orderBy('created_at')->get();

        $total = '0.00';
        $byMethod = [];
        foreach ($lines as $line) {
            $total = Money::add($total, $line->amount, 2);
            $byMethod[$line->method] = Money::add($byMethod[$line->method] ?? '0.00', $line->amount, 2);
        }

        $this->reference = $from->format('Y-m-d').' / '.$to->format('Y-m-d');
        $this->total = $total;

        return ['lines' => $lines, 'total' => $total, 'byMethod' => $byMethod, 'from' => $from, 'to' => $to];
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function total(): ?string
    {
        return $this->total;
    }

    public function filename(DocumentOptions $options): string
    {
        return 'payments-'.str_replace(' / ', '-to-', $this->reference).'.pdf';
    }
}
