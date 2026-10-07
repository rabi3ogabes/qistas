<?php

namespace App\Reports;

use App\Models\Contract;
use App\Models\Installment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The headline numbers on the dashboard. All money is returned as exact strings with two decimals.
 *
 * Definitions (kept simple and stated, so a figure can be checked by hand):
 *  - outstanding           what is still owed on instalments of contracts that are not cancelled
 *  - overdue               the part of that which was due before today (due today is not yet late)
 *  - collected_this_month  net money received in the calendar month: payments and down payments, minus voids
 *  - active_customers      customers with at least one running (active) contract
 *  - due_today             instalments due today and not fully paid, with what is still owed on each
 *  - collection_rate       of the instalments that fall due this month, the share already paid (percent)
 *
 * The month is the calendar month in the application timezone (UTC by default).
 */
final class DashboardMetrics
{
    public const DUE_TODAY_LIMIT = 50;

    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @return array{
     *     outstanding: string, overdue: string, collected_this_month: string, active_customers: int,
     *     collection_rate: ?string,
     *     due_today: list<array{installment_id: string, contract_id: string, contract_reference: string, customer_id: string, customer_name: string, amount_due: string, due_date: string}>
     * }
     */
    public function for(Tenant $tenant, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        return $this->current->use($tenant, fn () => [
            'outstanding' => $this->outstanding(),
            'overdue' => $this->overdue($now),
            'collected_this_month' => $this->collectedInMonth($now),
            'active_customers' => $this->activeCustomers(),
            'collection_rate' => $this->collectionRate($now),
            'due_today' => $this->dueToday($now),
        ]);
    }

    private function outstanding(): string
    {
        return $this->owed($this->liveInstallments());
    }

    private function overdue(CarbonInterface $now): string
    {
        // whereDate compares the calendar day on every database; a bare comparison would depend on how the
        // column happens to be stored (a date on PostgreSQL, text with a time part on SQLite).
        return $this->owed($this->liveInstallments()->whereDate('installments.due_date', '<', $now->toDateString()));
    }

    private function collectedInMonth(CarbonInterface $now): string
    {
        $sum = Transaction::query()
            ->whereBetween('paid_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('amount');

        return $this->money($sum);
    }

    private function activeCustomers(): int
    {
        return Contract::query()->where('status', 'active')->distinct()->count('customer_id');
    }

    private function collectionRate(CarbonInterface $now): ?string
    {
        $row = Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->whereDate('installments.due_date', '>=', $now->copy()->startOfMonth()->toDateString())
            ->whereDate('installments.due_date', '<=', $now->copy()->endOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(installments.amount), 0) as due, COALESCE(SUM(installments.paid_amount), 0) as paid')
            ->first();

        $due = $this->money($row->due ?? 0);
        if (Money::isZero($due)) {
            return null;
        }

        return Money::round(Money::div(Money::mul($this->money($row->paid ?? 0), '100'), $due), 1);
    }

    /** @return list<array{installment_id: string, contract_id: string, contract_reference: string, customer_id: string, customer_name: string, amount_due: string, due_date: string}> */
    private function dueToday(CarbonInterface $now): array
    {
        return Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->where('installments.status', '!=', 'paid')
            ->whereDate('installments.due_date', $now->toDateString())
            ->orderByRaw('LOWER(customers.name)')
            ->orderBy('contracts.number')
            ->limit(self::DUE_TODAY_LIMIT)
            // toBase() applies the tenant scope first, then gives plain rows rather than half-filled models.
            ->toBase()
            ->get(['installments.id', 'installments.amount', 'installments.paid_amount', 'installments.due_date',
                'contracts.id as contract_id', 'contracts.number as contract_number',
                'customers.id as customer_id', 'customers.name as customer_name'])
            ->map(fn (object $row): array => [
                'installment_id' => $row->id,
                'contract_id' => $row->contract_id,
                'contract_reference' => 'C-'.str_pad((string) $row->contract_number, 4, '0', STR_PAD_LEFT),
                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer_name,
                'amount_due' => Money::sub($this->money($row->amount), $this->money($row->paid_amount), 2),
                'due_date' => substr((string) $row->due_date, 0, 10),
            ])
            ->all();
    }

    /**
     * Instalments of contracts that are still being repaid or were repaid, never of cancelled ones.
     *
     * @return Builder<Installment>
     */
    private function liveInstallments(): Builder
    {
        return Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->where('contracts.status', '!=', 'cancelled');
    }

    /** @param  Builder<Installment>  $query */
    private function owed(Builder $query): string
    {
        return $this->money($query->sum(DB::raw('installments.amount - installments.paid_amount')));
    }

    /** A database aggregate as an exact two-decimal string (SQLite returns floats, PostgreSQL exact decimals). */
    private function money(mixed $value): string
    {
        $text = is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : sprintf('%.4f', (float) $value);

        return Money::round($text, 2);
    }
}
