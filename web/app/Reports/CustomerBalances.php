<?php

namespace App\Reports;

use App\Domain\Ledger\OpenAccount;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\LateFee;
use App\Support\Money;

/**
 * What customers and contracts still owe, for a page of them at a time. One grouped query per call, never one
 * per row. Cancelled contracts owe nothing, nor do superseded instalments; open late fees are owed (Win Plan K4); an
 * open contract owes its balance (which is negative when the customer paid ahead). Everything is scoped to the active
 * workspace by the models.
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
            ->whereNotIn('installments.status', Installment::SET_ASIDE)
            ->whereIn('contracts.customer_id', $customerIds)
            ->selectRaw('contracts.customer_id as customer_id, SUM(installments.amount - installments.paid_amount) as owed')
            ->groupBy('contracts.customer_id')
            ->toBase()->pluck('owed', 'customer_id');

        // Open late fees are owed too (Win Plan K4).
        $fees = LateFee::query()
            ->join('contracts', 'contracts.id', '=', 'late_fees.contract_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->where('late_fees.status', 'open')
            ->whereIn('contracts.customer_id', $customerIds)
            ->selectRaw('contracts.customer_id as customer_id, SUM(late_fees.amount - late_fees.paid_amount) as owed')
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
            $owing = Money::add(Money::fromDatabase($owed[$id] ?? 0), Money::fromDatabase($fees[$id] ?? 0), 2);
            $balances[$id] = ['owed' => Money::add($owing, $onAccount[$id] ?? '0', 2), 'running' => (int) ($running[$id] ?? 0)];
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
            ->whereNotIn('status', Installment::SET_ASIDE)
            ->selectRaw('contract_id, SUM(amount - paid_amount) as owed')
            ->groupBy('contract_id')
            ->toBase()->pluck('owed', 'contract_id');
        $onAccount = OpenAccount::balances(Contract::query()->whereIn('id', $contractIds)->where('type', 'open')->pluck('id')->all());
        $fees = LateFee::query()->whereIn('contract_id', $contractIds)->where('status', 'open')
            ->selectRaw('contract_id, SUM(amount - paid_amount) as owed')->groupBy('contract_id')->toBase()->pluck('owed', 'contract_id');

        $balances = [];
        foreach ($contractIds as $id) {
            $balances[$id] = $onAccount[$id] ?? Money::add(Money::fromDatabase($owed[$id] ?? 0), Money::fromDatabase($fees[$id] ?? 0), 2);
        }

        return $balances;
    }
}
