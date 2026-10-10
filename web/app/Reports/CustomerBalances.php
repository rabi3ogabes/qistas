<?php

namespace App\Reports;

use App\Domain\Ledger\OpenAccount;
use App\Models\Contract;
use App\Models\Installment;
use App\Support\Money;

/**
 * What customers and contracts still owe, for a page of them at a time. One grouped query per call, never one
 * per row. Cancelled contracts owe nothing, nor do superseded instalments; an open contract owes its balance (which is
 * negative when the customer paid ahead). Everything is scoped to the active workspace by the models.
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
            ->where('installments.status', '!=', 'superseded')
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

        $open = Contract::query()->where('type', 'open')->where('status', '!=', 'cancelled')->whereIn('customer_id', $customerIds)->pluck('customer_id', 'id');
        $onAccount = [];
        foreach (OpenAccount::balances($open->keys()->all()) as $contractId => $balance) {
            $onAccount[$open[$contractId]] = Money::add($onAccount[$open[$contractId]] ?? '0', $balance, 2);
        }

        $balances = [];
        foreach ($customerIds as $id) {
            $balances[$id] = ['owed' => Money::add(Money::fromDatabase($owed[$id] ?? 0), $onAccount[$id] ?? '0', 2), 'running' => (int) ($running[$id] ?? 0)];
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
            ->where('status', '!=', 'superseded')
            ->selectRaw('contract_id, SUM(amount - paid_amount) as owed')
            ->groupBy('contract_id')
            ->toBase()->pluck('owed', 'contract_id');
        $onAccount = OpenAccount::balances(Contract::query()->whereIn('id', $contractIds)->where('type', 'open')->pluck('id')->all());

        $balances = [];
        foreach ($contractIds as $id) {
            $balances[$id] = $onAccount[$id] ?? Money::fromDatabase($owed[$id] ?? 0);
        }

        return $balances;
    }
}
