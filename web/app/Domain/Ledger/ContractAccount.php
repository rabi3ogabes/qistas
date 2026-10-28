<?php

namespace App\Domain\Ledger;

use App\Models\Contract;
use App\Models\Installment;
use App\Models\LateFee;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One contract as the customer's page shows it: its account line by line with a running balance (see Statement), each
 * instalment that is past its date and still unpaid placed where it fell due, and the totals under it (monthly
 * instalment, instalments paid and left, paid, left and late). An open contract has no instalments, so no counts.
 *
 * Reads the active workspace's records; amounts are 2-decimal strings.
 */
final class ContractAccount
{
    /**
     * @param  list<array{date: CarbonInterface, kind: string, debit: string|null, credit: string|null, balance: string|null, transaction: Transaction|null, unpaid: Installment|null}>  $rows
     * @param  Collection<int, Installment>  $installments  the live schedule, in date order
     */
    private function __construct(
        public readonly Contract $contract,
        public readonly array $rows,
        public readonly Collection $installments,
        public readonly ?Installment $next,
        public readonly ?string $instalment,
        public readonly ?int $paidCount,
        public readonly ?int $leftCount,
        public readonly string $paid,
        public readonly string $left,
        public readonly string $late,
        public readonly string $feesOwed,
    ) {}

    public static function of(Contract $contract, ?CarbonInterface $today = null): self
    {
        $today ??= today();
        $contract->loadMissing('installments');
        $open = $contract->isOpen();
        $chase = $contract->status !== 'cancelled';

        // The live schedule, in date order: superseded instalments (a plan change) are history, not the plan.
        $live = $open ? collect() : $contract->installments->reject(fn (Installment $i) => $i->status === 'superseded')
            ->sortBy(fn (Installment $i) => [$i->due_date->format('Y-m-d'), $i->number])->values();

        $rows = [];
        foreach (Statement::of([$contract])->lines as $line) {
            $rows[] = ['date' => $line['date'], 'kind' => $line['kind'], 'debit' => $line['debit'], 'credit' => $line['credit'], 'balance' => $line['balance'], 'transaction' => $line['transaction'], 'unpaid' => null];
        }
        // Each instalment past its date and still unpaid, on the day it fell due, after that day's lines.
        if ($chase) {
            foreach ($live->filter(fn (Installment $i) => $i->isOverdue($today)) as $installment) {
                $rows[] = ['date' => $installment->due_date, 'kind' => 'unpaid', 'debit' => null, 'credit' => null, 'balance' => null, 'transaction' => null, 'unpaid' => $installment];
            }
        }
        $order = array_keys($rows);
        array_multisort(array_map(fn (array $row) => $row['date']->format('Y-m-d'), $rows), array_map(fn (array $row) => $row['unpaid'] === null ? 0 : 1, $rows), $order, $rows);

        $standing = Statement::owedAndOverdue(collect([$contract]), $today)[$contract->id];
        $paid = Transaction::query()->where('contract_id', $contract->id)->whereIn('type', Transaction::MONEY_IN)->pluck('amount')
            ->reduce(fn (string $sum, $amount) => Money::add($sum, (string) $amount, 2), '0.00');
        $feesOwed = ! $chase || $open ? '0.00' : LateFee::query()->where('contract_id', $contract->id)->where('status', 'open')->get()
            ->reduce(fn (string $sum, LateFee $fee) => Money::add($sum, $fee->remaining(), 2), '0.00');
        $next = $live->first(fn (Installment $i) => ! in_array($i->status, Installment::CLOSED, true));

        return new self(
            contract: $contract,
            rows: $rows,
            installments: $live,
            next: $next,
            instalment: $open ? null : ($next ?? $live->last())?->amount,
            paidCount: $open ? null : $live->where('status', 'paid')->count(),
            leftCount: $open ? null : $live->reject(fn (Installment $i) => in_array($i->status, Installment::CLOSED, true))->count(),
            paid: $paid,
            left: $standing['owed'],
            late: $standing['overdue'],
            feesOwed: $feesOwed,
        );
    }

    /** An instalment of this contract that can still take a payment, by id. */
    public function openInstallment(?string $id): ?Installment
    {
        return $id === null ? null : $this->installments->first(fn (Installment $i) => $i->id === $id && ! in_array($i->status, Installment::CLOSED, true));
    }

    /** Whether a payment can still go on it: an open account while it runs, otherwise an instalment or a late fee left. */
    public function takesPayments(): bool
    {
        if ($this->contract->status === 'cancelled') {
            return false;
        }

        return $this->contract->isOpen() ? $this->contract->status === 'active' : ($this->next !== null || Money::isPositive($this->feesOwed));
    }
}
