<?php

namespace App\Domain\LateFees;

use App\Models\LateFee;
use App\Models\LateFeeAllocation;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The active workspace's late fees over a period (Win Plan K4), the business's own apart from charity's: what was
 * charged (by the day each period began), collected (payments less their voids, by the day the money moved), waived
 * (by the day of the waiver: what was not paid on it), and what is still open today. Fees on cancelled contracts are
 * left out of what is open: nobody owes them.
 */
final class LateFeeSummary
{
    /** @return array{shop: array{charged: string, collected: string, waived: string, open: string}, charity: array{charged: string, collected: string, waived: string, open: string}} */
    public function between(CarbonInterface $from, CarbonInterface $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $summary = [];

        foreach (['shop' => false, 'charity' => true] as $side => $charity) {
            $fees = fn () => LateFee::query()->where('late_fees.charity', $charity);

            $summary[$side] = [
                'charged' => Money::fromDatabase($fees()->whereDate('charged_on', '>=', $start->format('Y-m-d'))->whereDate('charged_on', '<=', $end->format('Y-m-d'))->sum('amount')),
                'collected' => Money::fromDatabase(LateFeeAllocation::query()
                    ->join('late_fees', 'late_fees.id', '=', 'late_fee_allocations.late_fee_id')
                    ->join('transactions', 'transactions.id', '=', 'late_fee_allocations.transaction_id')
                    ->where('late_fees.charity', $charity)
                    ->whereBetween('transactions.paid_at', [$start, $end])
                    ->sum('late_fee_allocations.amount')),
                'waived' => Money::fromDatabase($fees()->where('status', 'waived')->whereBetween('waived_at', [$start, $end])->sum(DB::raw('amount - paid_amount'))),
                'open' => Money::fromDatabase($fees()->join('contracts', 'contracts.id', '=', 'late_fees.contract_id')
                    ->where('contracts.status', '!=', 'cancelled')->where('late_fees.status', 'open')
                    ->sum(DB::raw('late_fees.amount - late_fees.paid_amount'))),
            ];
        }

        return $summary;
    }
}
