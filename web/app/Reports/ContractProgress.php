<?php

namespace App\Reports;

use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;
use Illuminate\Database\Query\JoinClause;

/**
 * Where each contract stands, for a page of them at a time: what is still owed, which instalment is next, and
 * whether the contract is behind. A fixed number of queries per call, never one per row. Scoped to the active
 * workspace by the models.
 */
final class ContractProgress
{
    /**
     * @param  list<string>  $contractIds
     * @return array<string, array{owed: string, next: ?Installment, late: bool}> keyed by contract id
     */
    public function forContracts(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $owed = Installment::query()
            ->whereIn('contract_id', $contractIds)
            ->selectRaw('contract_id, SUM(amount - paid_amount) as owed')
            ->groupBy('contract_id')
            ->toBase()->pluck('owed', 'contract_id');

        // The next instalment is the earliest one not fully paid; numbers run in due-date order.
        $earliestUnpaid = Installment::query()
            ->whereIn('contract_id', $contractIds)
            ->where('status', '!=', 'paid')
            ->selectRaw('contract_id, MIN(number) as number')
            ->groupBy('contract_id')
            ->toBase();

        $next = Installment::query()
            ->joinSub($earliestUnpaid, 'earliest', fn (JoinClause $join) => $join
                ->on('earliest.contract_id', '=', 'installments.contract_id')
                ->on('earliest.number', '=', 'installments.number'))
            ->select('installments.*')
            ->get()->keyBy('contract_id');

        $progress = [];
        foreach ($contractIds as $id) {
            $nextInstallment = $next[$id] ?? null;
            $progress[$id] = [
                'owed' => Money::fromDatabase($owed[$id] ?? 0),
                'next' => $nextInstallment,
                // Due dates only grow, so if the next one is not overdue, none after it is.
                'late' => $nextInstallment?->isOverdue() ?? false,
            ];
        }

        return $progress;
    }

    /**
     * How many contracts each list holds, for the tabs: two queries however many contracts there are.
     *
     * @return array{all: int, active: int, late: int, settled: int, cancelled: int}
     */
    public function counts(): array
    {
        $byStatus = Contract::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->toBase()->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'late' => Contract::query()->inView('late')->count(),
            'settled' => (int) ($byStatus['settled'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
        ];
    }
}
