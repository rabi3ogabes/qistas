<?php

namespace App\Reports;

use App\Models\Installment;
use App\Models\LateFee;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * What the business can expect to collect (Win Plan K8, decision D7): what still falls due on running contracts in the
 * next 30, 60 and 90 days (each window counts from today, after what is already paid), week by week for 13 weeks, and
 * apart from it what was due before today and is still unpaid, and the open late fees. "Expected", never promised:
 * it is what the schedules say, not what customers will do.
 */
final class CashForecast
{
    public const WINDOWS = [30, 60, 90];

    public const WEEKS = 13;

    /** @return array{as_of: string, already_due: string, late_fees: string, windows: array{days_30: string, days_60: string, days_90: string}, weeks: list<array{from: string, to: string, amount: string}>} */
    public function for(ReportFilters $filters, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? today())->format('Y-m-d'));
        $contracts = $filters->contracts()->where('status', 'active')->select('id');
        $owed = fn () => Installment::query()->whereIn('contract_id', $contracts)->whereNotIn('status', Installment::CLOSED);
        $sum = fn ($query) => Money::fromDatabase($query->sum(DB::raw('amount - paid_amount')));

        $windows = [];
        foreach (self::WINDOWS as $days) {
            $windows['days_'.$days] = $sum($owed()->whereDate('due_date', '>=', $today->format('Y-m-d'))->whereDate('due_date', '<=', $today->addDays($days - 1)->format('Y-m-d')));
        }

        // Week by week: one grouped query, then the 13 weeks laid out with nothing missing.
        $byDay = $owed()->whereDate('due_date', '>=', $today->format('Y-m-d'))->whereDate('due_date', '<=', $today->addDays(self::WEEKS * 7 - 1)->format('Y-m-d'))
            ->selectRaw('due_date, SUM(amount - paid_amount) as owed')->groupBy('due_date')->toBase()->get();
        $weeks = [];
        for ($week = 0; $week < self::WEEKS; $week++) {
            $weeks[] = ['from' => $today->addDays($week * 7)->format('Y-m-d'), 'to' => $today->addDays($week * 7 + 6)->format('Y-m-d'), 'amount' => '0.00'];
        }
        foreach ($byDay as $row) {
            $index = intdiv((int) $today->diffInDays(CarbonImmutable::parse(substr((string) $row->due_date, 0, 10))), 7);
            $weeks[$index]['amount'] = Money::add($weeks[$index]['amount'], Money::fromDatabase($row->owed), 2);
        }

        return [
            'as_of' => $today->format('Y-m-d'),
            'already_due' => $sum($owed()->whereDate('due_date', '<', $today->format('Y-m-d'))),
            'late_fees' => Money::fromDatabase(LateFee::query()->whereIn('contract_id', $contracts)->where('status', 'open')->sum(DB::raw('amount - paid_amount'))),
            'windows' => $windows,
            'weeks' => $weeks,
        ];
    }
}
