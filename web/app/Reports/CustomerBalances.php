<?php

namespace App\Reports;

use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;

/**
 * What customers and contracts still owe, for a page of them at a time. One grouped query per call, never one
 * per row. Cancelled contracts owe nothing; everything is scoped to the active workspace by the models.
 */
final class CustomerBalances
{
    /**
     * @param  list<string>  $customerIds
     * @return array<string, array{owed: string, running: int}> keyed by customer id
     */
    public function forCustomers(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $owed = Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->whereIn('contracts.customer_id', $customerIds)
            ->selectRaw('contracts.customer_id as customer_id, SUM(installments.amount - installments.paid_amount) as owed')
            ->groupBy('contracts.customer_id')
            ->toBase()->pluck('owed', 'customer_id');

        $running = Contract::query()
            ->where('status', 'active')
            ->whereIn('customer_id', $customerIds)
            ->selectRaw('customer_id, COUNT(*) as running')
            ->groupBy('customer_id')
            ->toBase()->pluck('running', 'customer_id');

        $balances = [];
        foreach ($customerIds as $id) {
            $balances[$id] = ['owed' => Money::fromDatabase($owed[$id] ?? 0), 'running' => (int) ($running[$id] ?? 0)];
        }

        return $balances;
    }

    /**
     * @param  list<string>  $contractIds
     * @return array<string, string> what each contract still owes, keyed by contract id
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

        $balances = [];
        foreach ($contractIds as $id) {
            $balances[$id] = Money::fromDatabase($owed[$id] ?? 0);
        }

        return $balances;
    }
}
