<?php

namespace App\Domain\Investors;

use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * An investor's figures, all read from their entries and contracts:
 *
 *   wallet            what they hold: everything in, less everything out (the sum of their entries)
 *   out_in_contracts  principal funded and not back yet (cancelled contracts gave theirs back)
 *   profit_earned     profit shares as customers paid, with commissions passed on or received
 *   profit_expected   markup of running contracts not paid yet
 *   customers, contracts  the ones they fund, settled ones included, cancelled ones not
 */
final class InvestorSummary
{
    /** @return array{wallet: string, out_in_contracts: string, profit_earned: string, profit_expected: string, customers: int, contracts: int} */
    public static function for(Investor $investor): array
    {
        return app(CurrentTenant::class)->use($investor->tenant, function () use ($investor): array {
            $byType = InvestorEntry::query()->where('investor_id', $investor->id)
                ->selectRaw('type, COALESCE(SUM(amount), 0) as total')->groupBy('type')->pluck('total', 'type')
                ->map(fn (mixed $total) => Money::fromDatabase($total));
            $total = fn (string ...$types): string => array_reduce($types, fn (string $sum, string $type) => Money::add($sum, $byType[$type] ?? '0', 2), '0.00');

            $funded = Contract::query()->where('investor_id', $investor->id)->where('status', '!=', 'cancelled');
            $running = (clone $funded)->where('status', 'active');
            $markupDue = Money::fromDatabase((clone $running)->sum('markup_amount'));
            $profitIn = Money::fromDatabase(InvestorEntry::query()->where('investor_id', $investor->id)->where('type', 'profit_share')
                ->whereIn('contract_id', (clone $running)->select('id'))->sum('amount'));

            return [
                'wallet' => Money::fromDatabase($byType->reduce(fn (string $sum, string $amount) => Money::add($sum, $amount, 2), '0.00')),
                'out_in_contracts' => Money::sub('0', $total('funding_out', 'funding_back', 'principal_back'), 2),
                'profit_earned' => $total('profit_share', 'commission'),
                'profit_expected' => Money::sub($markupDue, $profitIn, 2),
                'customers' => (clone $funded)->distinct()->count('customer_id'),
                'contracts' => (clone $funded)->count(),
            ];
        });
    }

    /**
     * Profit earned in each of the last $months months, oldest first, for the investor's chart.
     *
     * @return list<array{month: string, amount: string}>
     */
    public static function profitByMonth(Investor $investor, int $months = 6): array
    {
        return app(CurrentTenant::class)->use($investor->tenant, function () use ($investor, $months): array {
            $from = today()->startOfMonth()->subMonths($months - 1);
            $month = DB::connection()->getDriverName() === 'pgsql' ? "to_char(occurred_on, 'YYYY-MM')" : "strftime('%Y-%m', occurred_on)";

            $totals = InvestorEntry::query()->where('investor_id', $investor->id)->whereIn('type', ['profit_share', 'commission'])
                ->whereDate('occurred_on', '>=', $from)
                ->selectRaw("{$month} as month, COALESCE(SUM(amount), 0) as total")->groupBy('month')->pluck('total', 'month');

            $series = [];
            for ($i = 0; $i < $months; $i++) {
                $key = $from->copy()->addMonths($i)->format('Y-m');
                $series[] = ['month' => $key, 'amount' => Money::fromDatabase($totals[$key] ?? 0)];
            }

            return $series;
        });
    }
}
