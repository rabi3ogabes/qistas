<?php

namespace App\Documents\Templates;

use App\Documents\DocumentOptions;
use App\Documents\Template;
use App\Domain\DayClose\DayTotals;
use App\Models\DayClose;
use App\Support\Money;
use App\Support\WorkspaceDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One close of one day, signed off (daily-habit wave A, H4): who closed it and when, the cash counted against the cash
 * recorded, the day's money by way of paying and by person, and the card, transfer and other payments one by one. The
 * figures are the ones kept with the close; the one list the close does not keep (the non-cash payments, each with its
 * customer's name) is read from the day, and the document says so when the day has had entries since. A reopened close
 * says it was reopened. No customer's identity is on it, only the name the payment was taken from.
 */
final class DayCloseSummary implements Template
{
    public function __construct(private readonly DayClose $close) {}

    public function kind(): string
    {
        return 'day_close';
    }

    public function subject(): Model
    {
        return $this->close;
    }

    public function view(): string
    {
        return 'documents.day-close-summary';
    }

    public function data(DocumentOptions $options): array
    {
        $close = $this->close->loadMissing(['closedBy', 'tenant']);
        $tenant = $close->tenant;
        $totals = $this->totals();

        $live = DayTotals::for($tenant, WorkspaceDay::on($tenant, $close->day));
        // Entries recorded for the day since it was closed: its figures moved and the signed ones did not.
        $changedSince = $live['count'] !== $totals['count'] || $this->normalised($live['by_method']) !== $this->normalised($totals['by_method']);

        return [
            'day' => CarbonImmutable::parse($close->day, 'UTC'),
            'closedBy' => $close->closedBy->name ?? __('Someone no longer on the team'),
            'closedAt' => $tenant->localTime($close->closed_at),
            'reopened' => $close->status === 'reopened',
            'expected' => Money::add($close->expected_cash, '0', 2),
            'counted' => Money::add($close->counted_cash, '0', 2),
            'difference' => Money::add($close->difference, '0', 2),
            'note' => $close->note,
            'byMethod' => $totals['by_method'],
            'collected' => $this->total(),
            'count' => $totals['count'],
            'perMember' => $totals['per_member'],
            'nonCash' => $live['non_cash'],
            'changedSince' => $changedSince,
        ];
    }

    /** The day, as the verification page shows it. */
    public function reference(): string
    {
        return $this->close->day;
    }

    /** All the money that came in on the day, every way of paying together, as it was when the day was closed. */
    public function total(): string
    {
        $total = '0.00';
        foreach ($this->totals()['by_method'] as $amount) {
            $total = Money::add($total, $amount, 2);
        }

        return $total;
    }

    public function filename(DocumentOptions $options): string
    {
        return 'day-summary-'.$this->close->day.'.pdf';
    }

    /**
     * What was kept with the close.
     *
     * @return array{by_method: array<string, string>, count: int, per_member: array<string, array{name: string, cash: string, count: int}>}
     */
    private function totals(): array
    {
        /** @var array{by_method?: array<string, string>, count?: int, per_member?: array<string, array{name: string, cash: string, count: int}>} $kept */
        $kept = $this->close->totals;

        return ['by_method' => $kept['by_method'] ?? [], 'count' => (int) ($kept['count'] ?? 0), 'per_member' => $kept['per_member'] ?? []];
    }

    /**
     * Each way of paying's total at two decimals, in a fixed order, so two lists of the same money compare equal.
     *
     * @param  array<string, string>  $byMethod
     * @return array<string, string>
     */
    private function normalised(array $byMethod): array
    {
        $amounts = array_map(fn (string $amount): string => Money::add($amount, '0', 2), $byMethod);
        ksort($amounts);

        return $amounts;
    }
}
