<?php

namespace App\Reports;

use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How late what is owed is (Win Plan RP15, decision D7): every unpaid instalment of a running contract in exactly one
 * bucket by the days since its last day of grace: current (not late yet), 1–30, 31–60, 61–90 and over 90. By bucket,
 * and contract by contract, the latest first.
 */
final class Ageing
{
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'];

    /**
     * @return array{as_of: string, buckets: array<string, string>, total: string, contracts: list<array<string, mixed>>}
     */
    public function for(ReportFilters $filters, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::parse(($today ?? today())->format('Y-m-d'));
        $contracts = $filters->contracts()->where('status', 'active')->with('customer')->get()->keyBy('id');
        $empty = array_fill_keys(self::BUCKETS, '0.00');
        $buckets = $empty;
        $rows = [];

        $installments = Installment::query()->whereIn('contract_id', $contracts->keys())->whereNotIn('status', Installment::CLOSED)->get();
        foreach ($installments as $installment) {
            $owed = Money::add($installment->remaining(), '0', 2);
            if (! Money::isPositive($owed)) {
                continue;
            }
            $bucket = self::bucket((int) ($installment->grace_until ?? $installment->due_date)->startOfDay()->diffInDays($today, false));
            $buckets[$bucket] = Money::add($buckets[$bucket], $owed, 2);
            $rows[$installment->contract_id] ??= $empty;
            $rows[$installment->contract_id][$bucket] = Money::add($rows[$installment->contract_id][$bucket], $owed, 2);
        }

        $list = [];
        foreach ($rows as $contractId => $row) {
            /** @var Contract $contract */
            $contract = $contracts[$contractId];
            $list[] = [
                'id' => $contract->id,
                'reference' => $contract->reference(),
                'customer' => ['id' => $contract->customer_id, 'name' => $contract->customer?->name],
                ...$row,
                'total' => array_reduce($row, fn (string $sum, string $amount) => Money::add($sum, $amount, 2), '0.00'),
            ];
        }
        // The latest first: what is over 90 days, then 61–90, and so on; then the larger total.
        usort($list, function (array $a, array $b): int {
            foreach (array_reverse(self::BUCKETS) as $bucket) {
                if (($order = Money::cmp($b[$bucket], $a[$bucket])) !== 0) {
                    return $order;
                }
            }

            return strcmp($a['reference'], $b['reference']);
        });

        return [
            'as_of' => $today->format('Y-m-d'),
            'buckets' => $buckets,
            'total' => array_reduce($buckets, fn (string $sum, string $amount) => Money::add($sum, $amount, 2), '0.00'),
            'contracts' => $list,
        ];
    }

    private static function bucket(int $daysLate): string
    {
        return match (true) {
            $daysLate <= 0 => 'current',
            $daysLate <= 30 => 'days_1_30',
            $daysLate <= 60 => 'days_31_60',
            $daysLate <= 90 => 'days_61_90',
            default => 'days_90_plus',
        };
    }
}
