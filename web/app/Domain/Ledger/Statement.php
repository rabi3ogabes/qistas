<?php

namespace App\Domain\Ledger;

use App\Models\Contract;
use App\Models\Transaction;
use App\Reports\CustomerBalances;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A customer's account, line by line (Win Plan PP8). A scheduled or cash contract opens with what the customer owes for
 * it: the price less any discount, plus the markup (its total plus the down payment). Every payment and down payment
 * takes from that, a void adds it back, and a cancelled contract closes at nothing owed. An open contract is its charges
 * and the payments that went on its account (see OpenAccount), so a converted contract starts from the balance it was
 * converted with. The running balance after the last line is what the rest of the app says is still owed.
 *
 * Reads the active workspace's records; amounts are 2-decimal strings, and an empty column is null.
 */
final class Statement
{
    /**
     * @param  list<array{date: CarbonInterface, contract: Contract, kind: string, debit: string|null, credit: string|null, balance: string, transaction: Transaction|null}>  $lines
     */
    private function __construct(public readonly string $opening, public readonly array $lines, public readonly string $closing) {}

    /**
     * @param  iterable<Contract>  $contracts
     * @param  CarbonInterface|null  $from  lines before this day make the opening balance
     * @param  CarbonInterface|null  $to  lines after this day are left out
     */
    public static function of(iterable $contracts, ?CarbonInterface $from = null, ?CarbonInterface $to = null): self
    {
        $lines = [];
        foreach ($contracts as $contract) {
            array_push($lines, ...self::linesOf($contract));
        }

        usort($lines, fn (array $a, array $b) => [$a['date']->format('Y-m-d'), $a['order'], $a['at']] <=> [$b['date']->format('Y-m-d'), $b['order'], $b['at']]);

        $opening = '0.00';
        $running = '0.00';
        $kept = [];
        foreach ($lines as $line) {
            $running = Money::add($running, Money::sub($line['debit'] ?? '0', $line['credit'] ?? '0'), 2);

            if ($to !== null && $line['date']->gt($to->copy()->endOfDay())) {
                continue;
            }
            if ($from !== null && $line['date']->lt($from->copy()->startOfDay())) {
                $opening = $running;

                continue;
            }

            unset($line['order'], $line['at']);
            $kept[] = $line + ['balance' => $running];
        }

        $closing = $kept === [] ? $opening : $kept[array_key_last($kept)]['balance'];

        return new self($opening, $kept, $closing);
    }

    /**
     * One contract's lines, unsorted, each with what orders it within its day.
     *
     * @return list<array{date: CarbonInterface, contract: Contract, kind: string, debit: string|null, credit: string|null, transaction: Transaction|null, order: int, at: string}>
     */
    private static function linesOf(Contract $contract): array
    {
        $lines = [];

        if ($contract->isOpen()) {
            $transactions = OpenAccount::lines()->where('contract_id', $contract->id)->with('createdBy')->get();
        } else {
            $sold = Money::add($contract->total, $contract->down_payment, 2);
            $lines[] = self::line($contract->start_date, $contract, 'sale', $sold, null, null, 0, '');
            $transactions = Transaction::query()->where('contract_id', $contract->id)->whereIn('type', Transaction::MONEY_IN)->with('createdBy')->get();
        }

        $balance = $lines === [] ? '0.00' : (string) $lines[0]['debit'];
        foreach ($transactions as $transaction) {
            // Money in is positive and a void negative; a charge is positive and a reversed charge negative.
            $amount = Money::add($transaction->amount, '0', 2);
            $charge = in_array($transaction->type, Transaction::CHARGES, true);
            $adds = $charge ? Money::cmp($amount, '0') > 0 : Money::cmp($amount, '0') < 0;
            $abs = Money::cmp($amount, '0') < 0 ? Money::sub('0', $amount, 2) : $amount;

            $balance = Money::add($balance, $adds ? $abs : Money::sub('0', $abs), 2);
            $lines[] = self::line($transaction->paid_at, $contract, $transaction->type, $adds ? $abs : null, $adds ? null : $abs, $transaction, 1, $transaction->created_at->format('Y-m-d H:i:s.u').$transaction->id);
        }

        // A cancelled contract owes nothing: what was left is written off on the day it was cancelled.
        if ($contract->status === 'cancelled' && $contract->cancelled_at !== null && Money::cmp($balance, '0') > 0) {
            $lines[] = self::line($contract->cancelled_at, $contract, 'cancelled', null, $balance, null, 2, '');
        }

        return $lines;
    }

    /** @return array{date: CarbonInterface, contract: Contract, kind: string, debit: string|null, credit: string|null, transaction: Transaction|null, order: int, at: string} */
    private static function line(CarbonInterface $date, Contract $contract, string $kind, ?string $debit, ?string $credit, ?Transaction $transaction, int $order, string $at): array
    {
        return ['date' => $date, 'contract' => $contract, 'kind' => $kind, 'debit' => $debit, 'credit' => $credit, 'transaction' => $transaction, 'order' => $order, 'at' => $at];
    }

    /**
     * Each contract's still-owed and late amounts today (cancelled contracts owe nothing).
     *
     * @param  Collection<int, Contract>  $contracts
     * @return array<string, array{owed: string, overdue: string}> keyed by contract id
     */
    public static function owedAndOverdue(Collection $contracts, ?CarbonInterface $today = null): array
    {
        $today ??= today();
        $owed = app(CustomerBalances::class)->forContracts($contracts->pluck('id')->all());

        $result = [];
        foreach ($contracts as $contract) {
            $overdue = '0.00';
            if ($contract->status !== 'cancelled' && ! $contract->isOpen()) {
                foreach ($contract->installments as $installment) {
                    if ($installment->isOverdue($today)) {
                        $overdue = Money::add($overdue, $installment->remaining(), 2);
                    }
                }
            }
            $result[$contract->id] = ['owed' => $contract->status === 'cancelled' ? '0.00' : Money::add($owed[$contract->id] ?? '0', '0', 2), 'overdue' => $overdue];
        }

        return $result;
    }
}
