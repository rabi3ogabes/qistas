<?php

namespace App\Reports;

use App\Domain\Investors\MainInvestor;
use App\Models\ContractItem;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\LateFeeAllocation;
use App\Models\Product;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Profit as money comes in (Win Plan K8, CT27, decision D7), read from the investor ledger itself, so it follows its
 * rule to the cent: each payment's share of the markup, an early settlement's rebate off profit, a void taking back
 * exactly what its payment gave. By month (the business's profit), by investor (what each kept, after a partner's
 * commission to the business) and by product (a contract's profit split by what each item sold for). Beside it, the
 * collection rate of the period and the late fees collected, the business's apart from charity's.
 */
final class ProfitReport
{
    /**
     * @return array{from: string, to: string, total: string, collection_rate: ?string, months: list<array{month: string, profit: string}>,
     *               investors: list<array{id: string, name: string, profit: string}>, products: list<array{id: ?string, name: string, profit: string}>,
     *               late_fees: array{shop: string, charity: string}}
     */
    public function for(ReportFilters $filters): array
    {
        $contractIds = $filters->contracts()->pluck('id');
        $entries = InvestorEntry::query()->whereIn('contract_id', $contractIds)->whereIn('type', ['profit_share', 'commission'])
            ->whereDate('occurred_on', '>=', $filters->from->format('Y-m-d'))->whereDate('occurred_on', '<=', $filters->to->format('Y-m-d'))
            ->get(['investor_id', 'type', 'amount', 'contract_id', 'occurred_on']);
        $weights = $this->weights($entries->pluck('contract_id')->unique()->filter()->values()->all());

        $months = [];
        for ($month = $filters->from->startOfMonth(); $month->lte($filters->to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = '0.00';
        }
        $total = '0.00';
        $investors = [];
        $products = [];
        foreach ($entries as $entry) {
            $shares = self::split(Money::add($entry->amount, '0', 2), $weights[$entry->contract_id] ?? ['' => '1']);
            if ($filters->productId !== null) {
                $shares = [$filters->productId => $shares[$filters->productId] ?? '0.00'];
            }
            $amount = array_reduce($shares, fn (string $sum, string $share) => Money::add($sum, $share, 2), '0.00');

            // A commission moves profit between investors: it is not the business's profit twice.
            if ($entry->type === 'profit_share') {
                $month = $entry->occurred_on->format('Y-m');
                $months[$month] = Money::add($months[$month] ?? '0.00', $amount, 2);
                $total = Money::add($total, $amount, 2);
                foreach ($shares as $product => $share) {
                    $products[$product] = Money::add($products[$product] ?? '0.00', $share, 2);
                }
            }
            $investors[$entry->investor_id] = Money::add($investors[$entry->investor_id] ?? '0.00', $amount, 2);
        }

        return [
            'from' => $filters->from->format('Y-m-d'),
            'to' => $filters->to->format('Y-m-d'),
            'total' => $total,
            'collection_rate' => $this->collectionRate($filters, $contractIds),
            'months' => array_map(fn (string $month, string $profit) => ['month' => $month, 'profit' => $profit], array_keys($months), $months),
            'investors' => $this->named($investors, fn (array $ids) => Investor::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Investor $i) => [$i->id => MainInvestor::displayName($i)])),
            'products' => $this->named($products, fn (array $ids) => Product::query()->whereIn('id', $ids)->pluck('name', 'id')),
            'late_fees' => $this->lateFees($filters, $contractIds),
        ];
    }

    /**
     * What each contract's products are worth in it, by product (the empty key: items not from the products list, or
     * no items at all): what each line sold for, or how many when no price was given.
     *
     * @param  list<string>  $contractIds
     * @return array<string, array<string, string>>
     */
    private function weights(array $contractIds): array
    {
        $weights = [];
        foreach (ContractItem::query()->whereIn('contract_id', $contractIds)->get()->groupBy('contract_id') as $contractId => $items) {
            $priced = $items->contains(fn (ContractItem $item) => $item->price !== null && Money::isPositive($item->price));
            foreach ($items as $item) {
                $weight = $priced ? Money::mul($item->price ?? '0', (string) $item->quantity, 4) : (string) $item->quantity;
                $key = (string) ($item->product_id ?? '');
                $weights[$contractId][$key] = Money::add($weights[$contractId][$key] ?? '0', $weight, 4);
            }
        }

        return $weights;
    }

    /**
     * [$amount] split by [$weights], to the cent; the last share takes what rounding left, so the shares add up.
     *
     * @param  array<string, string>  $weights
     * @return array<string, string>
     */
    private static function split(string $amount, array $weights): array
    {
        ksort($weights);
        $whole = array_reduce($weights, fn (string $sum, string $w) => Money::add($sum, $w, 4), '0');
        if (! Money::isPositive($whole)) {
            return ['' => $amount];
        }

        $shares = [];
        $left = $amount;
        $keys = array_keys($weights);
        foreach ($keys as $index => $key) {
            $share = $index === count($keys) - 1 ? $left : Money::round(Money::div(Money::mul($amount, $weights[$key], 8), $whole, 8), 2);
            $shares[$key] = $share;
            $left = Money::sub($left, $share, 2);
        }

        return $shares;
    }

    /**
     * @param  array<string, string>  $amounts  by id ('' for none)
     * @param  callable(list<string>): (Collection<string, string>|array<string, string>)  $names
     * @return list<array{id: ?string, name: string, profit: string}>
     */
    private function named(array $amounts, callable $names): array
    {
        $amounts = array_filter($amounts, fn (string $amount) => ! Money::isZero($amount));
        $known = collect($names(array_values(array_filter(array_keys($amounts), fn ($id) => $id !== ''))));
        $rows = [];
        foreach ($amounts as $id => $profit) {
            $rows[] = ['id' => $id === '' ? null : (string) $id, 'name' => $id === '' ? __('Not from the products list') : (string) ($known[$id] ?? __('Removed')), 'profit' => $profit];
        }
        usort($rows, fn (array $a, array $b) => Money::cmp($b['profit'], $a['profit']) ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Of what fell due in the period, the share paid so far, to one decimal; null when nothing fell due.
     *
     * @param  Collection<int, string>  $contractIds
     */
    private function collectionRate(ReportFilters $filters, Collection $contractIds): ?string
    {
        $row = Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->whereIn('installments.contract_id', $contractIds)
            ->where('contracts.status', '!=', 'cancelled')
            ->whereNotIn('installments.status', Installment::SET_ASIDE)
            ->whereDate('installments.due_date', '>=', $filters->from->format('Y-m-d'))
            ->whereDate('installments.due_date', '<=', $filters->to->format('Y-m-d'))
            ->selectRaw('COALESCE(SUM(installments.amount), 0) as due, COALESCE(SUM(installments.paid_amount), 0) as paid')
            ->first();
        $due = Money::fromDatabase($row->due ?? 0);

        return Money::isZero($due) ? null : Money::round(Money::div(Money::mul(Money::fromDatabase($row->paid ?? 0), '100', 6), $due, 6), 1);
    }

    /**
     * Late fees collected in the period (payments less their voids), the business's apart from charity's (Win Plan K4).
     *
     * @param  Collection<int, string>  $contractIds
     * @return array{shop: string, charity: string}
     */
    private function lateFees(ReportFilters $filters, Collection $contractIds): array
    {
        $collected = LateFeeAllocation::query()
            ->join('late_fees', 'late_fees.id', '=', 'late_fee_allocations.late_fee_id')
            ->join('transactions', 'transactions.id', '=', 'late_fee_allocations.transaction_id')
            ->whereIn('late_fees.contract_id', $contractIds)
            ->whereBetween('transactions.paid_at', [CarbonImmutable::parse($filters->from)->startOfDay(), CarbonImmutable::parse($filters->to)->endOfDay()])
            ->selectRaw('late_fees.charity as charity, SUM(late_fee_allocations.amount) as collected')
            ->groupBy('late_fees.charity')->toBase()->pluck('collected', 'charity');

        $charity = '0.00';
        $shop = '0.00';
        foreach ($collected as $isCharity => $amount) {
            if ((bool) $isCharity) {
                $charity = Money::add($charity, Money::fromDatabase($amount), 2);
            } else {
                $shop = Money::add($shop, Money::fromDatabase($amount), 2);
            }
        }

        return ['shop' => $shop, 'charity' => $charity];
    }
}
