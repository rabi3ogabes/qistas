<?php

namespace App\Domain\Ledger;

use App\Models\Contract;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The balance of an open contract (Win Plan PP4): what the customer took (charges, less charges reversed) less what
 * they paid on account. A payment on account is one that paid no instalment: every payment of an open contract, and
 * none of the payments a contract took while it still had a schedule (those settled instalments, and what was left
 * of the schedule came over as one opening charge). Never negative by force: a customer who paid ahead has credit.
 */
final class OpenAccount
{
    /**
     * The lines that make an open contract's balance.
     *
     * @return Builder<Transaction>
     */
    public static function lines(): Builder
    {
        return Transaction::query()->where(fn (Builder $query) => $query
            ->whereIn('type', Transaction::CHARGES)
            ->orWhere(fn (Builder $paid) => $paid
                ->whereIn('type', ['payment', 'reversal'])
                ->whereNotExists(fn (QueryBuilder $allocation) => $allocation->select(DB::raw(1))->from('transaction_allocations')
                    ->whereColumn('transaction_allocations.transaction_id', 'transactions.id'))));
    }

    /** How a line moves the balance: a charge adds to what is owed, money in takes from it. */
    public static function effect(Transaction $line): string
    {
        return in_array($line->type, Transaction::CHARGES, true) ? $line->amount : Money::sub('0', $line->amount);
    }

    /**
     * @param  list<string>  $contractIds  open contracts
     * @return array<string, string> the balance of each, keyed by contract id
     */
    public static function balances(array $contractIds): array
    {
        if ($contractIds === []) {
            return [];
        }

        $totals = self::lines()->whereIn('contract_id', $contractIds)
            ->selectRaw("contract_id, COALESCE(SUM(CASE WHEN type IN ('charge', 'charge_reversal') THEN amount ELSE -amount END), 0) as balance")
            ->groupBy('contract_id')->toBase()->pluck('balance', 'contract_id');

        $balances = [];
        foreach ($contractIds as $id) {
            $balances[$id] = Money::fromDatabase($totals[$id] ?? 0);
        }

        return $balances;
    }

    public static function balance(Contract $contract): string
    {
        return self::balances([$contract->id])[$contract->id];
    }

    /**
     * The balance after each line, for a ledger read in order: keyed by transaction id, for the lines that count.
     *
     * @return array<string, string>
     */
    public static function runningBalances(Contract $contract): array
    {
        $running = '0.00';
        $after = [];
        foreach (self::lines()->where('contract_id', $contract->id)->orderBy('paid_at')->orderBy('created_at')->orderBy('id')->get() as $line) {
            $running = Money::add($running, self::effect($line), 2);
            $after[$line->id] = $running;
        }

        return $after;
    }
}
