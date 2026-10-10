<?php

namespace App\Reports;

use App\Domain\Ledger\OpenAccount;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The headline numbers on the dashboard. All money is returned as exact strings with two decimals.
 *
 * Definitions (kept simple and stated, so a figure can be checked by hand):
 *  - outstanding           what is still owed on instalments of contracts that are not cancelled, and the balances
 *                          of open contracts that owe something (a customer who paid ahead owes nothing here)
 *  - overdue               the part of that which was due before today (due today is not yet late)
 *  - collected_this_month  net money received in the calendar month: payments and down payments, minus voids
 *                          (what an open contract's customer took is owed, not received, and never counts)
 *  - active_customers      customers with at least one running (active) contract
 *  - due_today             instalments due today and not fully paid, with what is still owed on each
 *  - collection_rate       of the instalments that fall due this month, the share already paid (percent)
 *
 * The month is the calendar month in the application timezone (UTC by default).
 */
final class DashboardMetrics
{
    public const DUE_TODAY_LIMIT = 50;

    /** How many late or coming instalments the briefing lists; the rest are one tap away in the contracts. */
    public const LIST_LIMIT = 25;

    /** How many the remind-all checklist goes through in one sitting (Win Plan PP9). */
    public const REMIND_LIMIT = 200;

    private const ROW_COLUMNS = [
        'installments.id', 'installments.amount', 'installments.paid_amount', 'installments.due_date',
        'contracts.id as contract_id', 'contracts.number as contract_number',
        'customers.id as customer_id', 'customers.name as customer_name', 'customers.phone as customer_phone',
    ];

    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @return array{
     *     outstanding: string, overdue: string, collected_this_month: string, active_customers: int,
     *     collection_rate: ?string,
     *     due_today: list<array{installment_id: string, contract_id: string, contract_reference: string, customer_id: string, customer_name: string, customer_phone: string, amount_due: string, due_date: string}>
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
        $open = Contract::query()->where('type', 'open')->where('status', '!=', 'cancelled')->pluck('id')->all();
        $owedOnAccount = array_reduce(OpenAccount::balances($open), fn (string $sum, string $balance) => Money::isPositive($balance) ? Money::add($sum, $balance, 2) : $sum, '0.00');

        return Money::add($this->owed($this->liveInstallments()), $owedOnAccount, 2);
    }

    private function overdue(CarbonInterface $now): string
    {
        // whereDate compares the calendar day on every database; a bare comparison would depend on how the
        // column happens to be stored (a date on PostgreSQL, text with a time part on SQLite).
        // Late means past the last day of grace (the due date itself when a contract has no grace days).
        return $this->owed($this->liveInstallments()->whereDate('installments.grace_until', '<', $now->toDateString()));
    }

    private function collectedInMonth(CarbonInterface $now): string
    {
        $sum = Transaction::query()->moneyIn()
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
            ->where('installments.status', '!=', 'superseded')
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

    /**
     * Who pays today, for the remind-all checklist (Win Plan PP9): exactly the rows the dashboard lists, with more of them.
     *
     * @return list<array<string, string>>
     */
    public function dueTodayList(Tenant $tenant, CarbonInterface $now, int $limit = self::REMIND_LIMIT): array
    {
        return $this->current->use($tenant, fn () => $this->dueToday($now, $limit));
    }

    /**
     * Who is late, oldest first, for the remind-all checklist: the briefing's rows, with more of them.
     *
     * @return list<array<string, mixed>>
     */
    public function lateList(Tenant $tenant, CarbonInterface $now, int $limit = self::REMIND_LIMIT): array
    {
        return $this->current->use($tenant, fn () => $this->lateRows($now, $limit));
    }

    /** @return list<array<string, string>> */
    private function dueToday(CarbonInterface $now, int $limit = self::DUE_TODAY_LIMIT): array
    {
        return Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->whereNotIn('installments.status', Installment::CLOSED)
            // Due today, or due already but still within its grace days: it needs collecting, and is not late yet.
            ->whereDate('installments.due_date', '<=', $now->toDateString())
            ->whereDate('installments.grace_until', '>=', $now->toDateString())
            ->orderBy('installments.due_date')
            ->orderByRaw('LOWER(customers.name)')
            ->orderBy('contracts.number')
            ->limit($limit)
            // toBase() applies the tenant scope first, then gives plain rows rather than half-filled models.
            ->toBase()
            ->get(self::ROW_COLUMNS)
            ->map(fn (object $row): array => $this->row($row))
            ->all();
    }

    /**
     * What an app needs to start the day well, beyond the headline figures: who is late (oldest first) and who is
     * about to be, how this month compares with last, and the last two weeks of takings for a small chart.
     *
     * @return array{
     *     expected_this_month: string, collected_last_month: string,
     *     overdue_list: list<array<string, mixed>>, upcoming: list<array<string, mixed>>,
     *     daily_collected: list<array{date: string, amount: string}>
     * }
     */
    public function briefing(Tenant $tenant, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        return $this->current->use($tenant, fn () => [
            'expected_this_month' => $this->expectedInMonth($now),
            'collected_last_month' => $this->collectedInMonth($now->copy()->subMonthNoOverflow()),
            'overdue_list' => $this->lateRows($now),
            'upcoming' => $this->comingRows($now),
            'daily_collected' => $this->dailyCollected($now),
        ]);
    }

    private function expectedInMonth(CarbonInterface $now): string
    {
        return $this->money(
            Installment::query()
                ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
                ->where('contracts.status', '!=', 'cancelled')
                ->where('installments.status', '!=', 'superseded')
                ->whereDate('installments.due_date', '>=', $now->copy()->startOfMonth()->toDateString())
                ->whereDate('installments.due_date', '<=', $now->copy()->endOfMonth()->toDateString())
                ->sum('installments.amount'),
        );
    }

    /** @return list<array<string, mixed>> the oldest unpaid instalments first, each with how many days late it is */
    private function lateRows(CarbonInterface $now, int $limit = self::LIST_LIMIT): array
    {
        return $this->openInstallments()
            ->whereDate('installments.grace_until', '<', $now->toDateString())
            ->orderBy('installments.due_date')
            ->orderByRaw('LOWER(customers.name)')
            ->limit($limit)
            ->toBase()
            ->get(self::ROW_COLUMNS)
            ->map(fn (object $row): array => $this->row($row) + ['days_late' => $this->daysBetween($row->due_date, $now)])
            ->all();
    }

    /** @return list<array<string, mixed>> what falls due in the next seven days, soonest first */
    private function comingRows(CarbonInterface $now): array
    {
        return $this->openInstallments()
            ->whereDate('installments.due_date', '>', $now->toDateString())
            ->whereDate('installments.due_date', '<=', $now->copy()->addDays(7)->toDateString())
            ->orderBy('installments.due_date')
            ->orderByRaw('LOWER(customers.name)')
            ->limit(self::LIST_LIMIT)
            ->toBase()
            ->get(self::ROW_COLUMNS)
            ->map(fn (object $row): array => $this->row($row) + ['days_until' => $this->daysBetween($row->due_date, $now)])
            ->all();
    }

    /** @return list<array{date: string, amount: string}> the last fourteen days including today, oldest first */
    private function dailyCollected(CarbonInterface $now): array
    {
        $first = $now->copy()->subDays(13)->startOfDay();

        $totals = Transaction::query()->moneyIn()
            ->whereBetween('paid_at', [$first, $now->copy()->endOfDay()])
            ->toBase()
            ->selectRaw('DATE(paid_at) as day, SUM(amount) as total')
            ->groupByRaw('DATE(paid_at)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->day, 0, 10) => $this->money($row->total)]);

        return collect(range(0, 13))->map(function (int $offset) use ($first, $totals): array {
            $date = $first->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'amount' => $totals->get($date, '0.00')];
        })->all();
    }

    /** @return Builder<Installment> unpaid instalments of contracts that are not cancelled, with their customer */
    private function openInstallments(): Builder
    {
        return Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->whereNotIn('installments.status', Installment::CLOSED);
    }

    private function daysBetween(mixed $dueDate, CarbonInterface $now): int
    {
        return (int) abs(Carbon::parse(substr((string) $dueDate, 0, 10))->startOfDay()->diffInDays($now->copy()->startOfDay()));
    }

    /** @return array<string, string> */
    private function row(object $row): array
    {
        return [
            'installment_id' => $row->id,
            'contract_id' => $row->contract_id,
            'contract_reference' => 'C-'.str_pad((string) $row->contract_number, 4, '0', STR_PAD_LEFT),
            'customer_id' => $row->customer_id,
            'customer_name' => $row->customer_name,
            'customer_phone' => (string) $row->customer_phone,
            'amount_due' => Money::sub($this->money($row->amount), $this->money($row->paid_amount), 2),
            'due_date' => substr((string) $row->due_date, 0, 10),
        ];
    }

    /**
     * Instalments of contracts that are still being repaid or were repaid, never of cancelled ones, nor the ones an
     * open contract superseded.
     *
     * @return Builder<Installment>
     */
    private function liveInstallments(): Builder
    {
        return Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->where('installments.status', '!=', 'superseded');
    }

    /** @param  Builder<Installment>  $query */
    private function owed(Builder $query): string
    {
        return $this->money($query->sum(DB::raw('installments.amount - installments.paid_amount')));
    }

    private function money(mixed $value): string
    {
        return Money::fromDatabase($value);
    }
}
