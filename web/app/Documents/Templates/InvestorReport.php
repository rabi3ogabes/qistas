<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Domain\Investors\InvestorSummary;
use App\Domain\Ledger\Statement;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An investor's account: their figures, the contracts they fund with what is owed and late on each, and every entry of
 * the period with the wallet's running balance.
 */
final class InvestorReport implements Template
{
    private string $reference = '';

    private ?string $wallet = null;

    public function __construct(private readonly Investor $investor) {}

    public function kind(): string
    {
        return 'investor_report';
    }

    public function subject(): Model
    {
        return $this->investor;
    }

    public function view(): string
    {
        return 'documents.investor-report';
    }

    public function data(DocumentOptions $options): array
    {
        $summary = InvestorSummary::for($this->investor);

        $contracts = Contract::query()->where('investor_id', $this->investor->id)->with(['customer', 'installments'])->orderBy('number')->get();
        $balances = Statement::owedAndOverdue($contracts);

        $running = '0.00';
        $opening = '0.00';
        $entries = [];
        $all = InvestorEntry::query()->where('investor_id', $this->investor->id)->with('contract')->orderBy('occurred_on')->orderBy('created_at')->orderBy('id')->get();
        foreach ($all as $entry) {
            $running = Money::add($running, $entry->amount, 2);
            if ($options->to !== null && $entry->occurred_on->gt($options->to)) {
                continue;
            }
            if ($options->from !== null && $entry->occurred_on->lt($options->from)) {
                $opening = $running;

                continue;
            }
            $entries[] = ['entry' => $entry, 'amount' => Money::add($entry->amount, '0', 2), 'balance' => $running];
        }

        $to = $options->to ?? CarbonImmutable::today();
        $this->reference = ($options->from?->format('Y-m-d') ?? '…').' / '.$to->format('Y-m-d');
        $this->wallet = $summary['wallet'];

        return [
            'investor' => $this->investor,
            'summary' => $summary,
            'contracts' => $contracts->map(fn (Contract $c) => ['contract' => $c] + $balances[$c->id])->all(),
            'entries' => $entries,
            'opening' => $opening,
        ];
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function total(): ?string
    {
        return $this->wallet;
    }

    public function filename(DocumentOptions $options): string
    {
        $name = Str::slug($this->investor->name, language: 'en');

        return 'investor'.($name === '' ? '' : '-'.$name).'-'.today()->format('Y-m-d').'.pdf';
    }
}
