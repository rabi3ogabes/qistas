<?php

namespace App\Notifications\Alerts;

use App\Models\Installment;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the alerts count and name (Win Plan PP9), for the workspace in context and its own today. "Due today" and "late"
 * mean what they mean on the dashboard: due already but within the grace days, and past them.
 */
final class AlertQueries
{
    private const COLUMNS = [
        'installments.id', 'installments.amount', 'installments.paid_amount', 'installments.due_date', 'installments.grace_until',
        'contracts.id as contract_id', 'contracts.number as contract_number', 'customers.name as customer_name',
    ];

    /**
     * How many pay today and how many are late, with what they owe, and how many are past the business's late limit.
     *
     * @return array{due_count: int, due_amount: string, late_count: int, late_amount: string, past_limit: int}
     */
    public function totals(CarbonInterface $today, int $lateAfterDays): array
    {
        $date = $today->toDateString();
        $due = $this->open()->whereDate('installments.due_date', '<=', $date)->whereDate('installments.grace_until', '>=', $date);
        $late = $this->open()->whereDate('installments.grace_until', '<', $date);

        return [
            'due_count' => (clone $due)->count(),
            'due_amount' => $this->owed($due),
            'late_count' => (clone $late)->count(),
            'late_amount' => $this->owed($late),
            'past_limit' => (clone $late)->whereDate('installments.due_date', '<', $today->copy()->subDays($lateAfterDays)->toDateString())->count(),
        ];
    }

    /**
     * The instalments that fall due on [$today] itself.
     *
     * @return list<object>
     */
    public function dueOn(CarbonInterface $today): array
    {
        return $this->open()->whereDate('installments.due_date', $today->toDateString())
            ->orderByRaw('LOWER(customers.name)')->toBase()->get(self::COLUMNS)->all();
    }

    /**
     * The instalments past their grace days, oldest first, each with how many days it has been late.
     *
     * @return list<object>
     */
    public function late(CarbonInterface $today): array
    {
        $date = $today->toDateString();

        return $this->open()->whereDate('installments.grace_until', '<', $date)
            ->orderBy('installments.due_date')->orderByRaw('LOWER(customers.name)')
            ->toBase()->get(self::COLUMNS)
            ->map(function (object $row) use ($today): object {
                // Calendar days, both read as plain dates: the workspace's today is in its own zone, the column has none.
                $row->days_late = (int) Carbon::parse(substr((string) $row->grace_until, 0, 10))->diffInDays(Carbon::parse($today->toDateString()));

                return $row;
            })->all();
    }

    /** What is still owed on an instalment row. */
    public static function remaining(object $row): string
    {
        return Money::sub(Money::fromDatabase($row->amount), Money::fromDatabase($row->paid_amount), 2);
    }

    /** @return Builder<Installment> unpaid instalments of contracts that are not cancelled, with their customer */
    private function open(): Builder
    {
        return Installment::query()
            ->join('contracts', 'contracts.id', '=', 'installments.contract_id')
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->where('contracts.status', '!=', 'cancelled')
            ->whereNotIn('installments.status', Installment::CLOSED);
    }

    /** @param  Builder<Installment>  $query */
    private function owed(Builder $query): string
    {
        return Money::fromDatabase((clone $query)->sum(DB::raw('installments.amount - installments.paid_amount')));
    }
}
