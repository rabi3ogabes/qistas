<?php

namespace App\Domain\DayClose;

use App\Models\Contract;
use App\Models\LateFee;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the workspace recorded in one of its days (daily-habit wave A, H4): the one place that works the day's figures
 * out, so closing the day, reopening it, the API and both screens all read the same numbers.
 *
 * The day is a WorkspaceDay, and every range is cut in SQL on the timestamp column as [start, end): never by the
 * database's own date, which is not the workspace's. A payment belongs to the day it was *taken* (`paid_at`), a void to
 * the day it was *recorded*: a void today of cash taken yesterday lowers today's expected cash, and yesterday keeps
 * what it had. Only the ledger's money-in lines count (payments, down payments and their reversals); what an open
 * contract's customer took is owed, never cash. What an import brought in as already paid ("Paid before Qistas", tagged
 * Transaction::IMPORTED), and a void of it, is money of the shop's past, never in one of its days: it is left out of
 * every figure here, as it never went through a till.
 *
 * The queries are grouped in SQL, one for each section of the day, so the cost does not grow with the day's payments.
 */
final class DayTotals
{
    /** What a person takes: the ledger's money-in lines that are not a reversal. */
    private const PAYMENTS = ['payment', 'down_payment'];

    /** The key of `per_member` for money recorded by someone who has since left the team (or by no one). */
    private const UNKNOWN_MEMBER = 'unknown';

    /** Up to this many contracts opened in a day are listed one by one; more (an import) are one line saying how many. */
    private const OPENED_LISTED = 10;

    /**
     * @return array{
     *     day: string,
     *     expected_cash: string,
     *     by_method: array<string, string>,
     *     count: int,
     *     per_member: array<string, array{name: string, cash: string, count: int}>,
     *     non_cash: list<array{id: string, customer: string, amount: string, method: string, note: ?string}>,
     *     watch: list<array{kind: 'void'|'waived_fee'|'back_dated'|'opened'|'cancelled', id: string, label: string, amount: ?string}>
     * }
     *
     * `expected_cash` is the day's net cash: cash taken, less cash reversed in the day, so it is negative on a day that
     * only gave money back. `by_method` is the net of every method that had a line in the day, in the order the
     * workspace lists its payment methods. `count` is the payments taken in the day that were not voided in it, one for
     * each payment however many contracts it was spread across (a payment across a customer's contracts is one ledger
     * line per contract, tied by its batch, and is one payment until all of its lines are voided in the day).
     * `per_member` is the cash side of the day by the person who recorded each line (a void is the voider's line), keyed
     * by user id, so the cash of the members adds up to `expected_cash`, and their counts add up to the cash payments of
     * `count` (a batch is recorded by one person, so it is one of that person's payments); money recorded by someone who
     * has since left the team sits under the key `unknown`. `non_cash` lists every card, transfer, cheque and other
     * line, a void as its own negative line. `watch` lists what is worth a look, kind by kind, each with the customer's
     * name and the amount the item concerned (always positive; null for an open account, which has no price); when more
     * than ten contracts were opened in the day they are one `opened` line instead, labelled with how many, with the id
     * of the first of them and the sum of their prices.
     */
    public static function for(Tenant $tenant, WorkspaceDay $day): array
    {
        return app(CurrentTenant::class)->use($tenant, function () use ($day): array {
            $money = self::money($day);

            return [
                'day' => $day->date,
                'expected_cash' => $money['expected_cash'],
                'by_method' => $money['by_method'],
                'count' => $money['count'],
                'per_member' => $money['per_member'],
                'non_cash' => self::nonCash($day),
                'watch' => self::watch($day),
            ];
        });
    }

    /**
     * Cash and count, by method and by member, from one grouped query over the day's money-in lines.
     *
     * @return array{expected_cash: string, by_method: array<string, string>, count: int, per_member: array<string, array{name: string, cash: string, count: int}>}
     */
    private static function money(WorkspaceDay $day): array
    {
        $payments = "'".implode("', '", self::PAYMENTS)."'";

        // A payment counts unless a reversal of it was recorded in the same day: the person who took it and the person
        // who voided it are often not the same, so the count follows the payment, never the void. A payment across a
        // customer's contracts is a line on each, tied by its batch, and counts once: lines are counted by their batch,
        // a line on its own by itself. A batch has one person and one method, so it sits in one group of the query.
        $rows = self::tillLines(Transaction::query()->moneyIn())
            ->where('paid_at', '>=', $day->start)->where('paid_at', '<', $day->end)
            ->selectRaw(
                "created_by_user_id, method, SUM(amount) AS total, COUNT(DISTINCT CASE WHEN type IN ({$payments}) AND NOT EXISTS ("
                .'SELECT 1 FROM transactions AS voids WHERE voids.reverses_transaction_id = transactions.id AND voids.paid_at >= ? AND voids.paid_at < ?'
                .') THEN COALESCE(transactions.batch_id, transactions.id) END) AS taken',
                [$day->start, $day->end],
            )
            ->groupBy('created_by_user_id', 'method')
            ->toBase()->get();

        $byMethod = [];
        $members = [];
        $count = 0;
        foreach ($rows as $row) {
            $total = Money::fromDatabase($row->total);
            $byMethod[$row->method] = Money::add($byMethod[$row->method] ?? '0.00', $total, 2);
            $count += (int) $row->taken;
            if ($row->method === 'cash') {
                $members[$row->created_by_user_id ?? self::UNKNOWN_MEMBER] = ['cash' => $total, 'count' => (int) $row->taken];
            }
        }

        return [
            'expected_cash' => $byMethod['cash'] ?? '0.00',
            'by_method' => self::inMethodOrder($byMethod),
            'count' => $count,
            'per_member' => self::named($members),
        ];
    }

    /**
     * The members' cash with each one's name, people first by name, then whoever has left.
     *
     * @param  array<string, array{cash: string, count: int}>  $members
     * @return array<string, array{name: string, cash: string, count: int}>
     */
    private static function named(array $members): array
    {
        $ids = array_values(array_filter(array_keys($members), fn (string $id): bool => $id !== self::UNKNOWN_MEMBER));
        $names = $ids === [] ? new Collection : User::query()->whereIn('id', $ids)->pluck('name', 'id');

        $named = [];
        foreach ($members as $id => $member) {
            $named[$id] = ['name' => (string) ($names[$id] ?? __('Someone no longer on the team')), 'cash' => $member['cash'], 'count' => $member['count']];
        }

        uksort($named, function (string $a, string $b) use ($named): int {
            return [$a === self::UNKNOWN_MEMBER, mb_strtolower($named[$a]['name']), $a] <=> [$b === self::UNKNOWN_MEMBER, mb_strtolower($named[$b]['name']), $b];
        });

        return $named;
    }

    /**
     * Methods in the order the workspace lists them (cash first), any other after, so a summary reads the same every day.
     *
     * @param  array<string, string>  $byMethod
     * @return array<string, string>
     */
    private static function inMethodOrder(array $byMethod): array
    {
        $order = array_flip((array) config('qistas.payment_methods'));
        uksort($byMethod, fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return $byMethod;
    }

    /**
     * [$query] without what an import brought in as already paid, and without a void of it: neither went through a till.
     * A void is matched by the line it reverses, as it carries no tag of its own.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    private static function tillLines(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $line) => $line->whereNull('transactions.tag')->orWhere('transactions.tag', '!=', Transaction::IMPORTED))
            ->whereNotExists(fn (QueryBuilder $imported) => $imported->select(DB::raw(1))->from('transactions as imported')
                ->whereColumn('imported.id', 'transactions.reverses_transaction_id')
                ->where('imported.tag', Transaction::IMPORTED));
    }

    /**
     * Every card, transfer, cheque and other money-in line of the day, oldest first; a reversal is its own negative line.
     *
     * @return list<array{id: string, customer: string, amount: string, method: string, note: ?string}>
     */
    private static function nonCash(WorkspaceDay $day): array
    {
        // The customer is joined, not loaded: a customer deleted afterwards is still the one who paid.
        $rows = self::tillLines(Transaction::query()->moneyIn())
            ->join('customers', 'customers.id', '=', 'transactions.customer_id')
            ->where('transactions.method', '!=', 'cash')
            ->where('transactions.paid_at', '>=', $day->start)->where('transactions.paid_at', '<', $day->end)
            ->orderBy('transactions.paid_at')->orderBy('transactions.created_at')->orderBy('transactions.id')
            ->toBase()->get(['transactions.id', 'transactions.amount', 'transactions.method', 'transactions.note', 'customers.name']);

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'id' => (string) $row->id,
                'customer' => (string) $row->name,
                'amount' => Money::fromDatabase($row->amount),
                'method' => (string) $row->method,
                'note' => $row->note === null ? null : (string) $row->note,
            ];
        }

        return $lines;
    }

    /**
     * What is worth a look in the day, a query for each kind (five, however busy the day): a payment voided, a late fee
     * waived, a payment recorded in the day that belongs to an earlier one, a contract opened and a contract cancelled.
     *
     * @return list<array{kind: 'void'|'waived_fee'|'back_dated'|'opened'|'cancelled', id: string, label: string, amount: ?string}>
     */
    private static function watch(WorkspaceDay $day): array
    {
        $watch = [];

        // A void is a reversal row, dated the moment it was recorded (the same column that moves the day's cash).
        $voids = Transaction::query()
            ->join('customers', 'customers.id', '=', 'transactions.customer_id')
            ->where('transactions.type', 'reversal')
            ->where('transactions.paid_at', '>=', $day->start)->where('transactions.paid_at', '<', $day->end)
            ->orderBy('transactions.paid_at')->orderBy('transactions.created_at')->orderBy('transactions.id')
            ->toBase()->get(['transactions.id', 'transactions.amount', 'customers.name']);
        foreach ($voids as $row) {
            $watch[] = ['kind' => 'void', 'id' => (string) $row->id, 'label' => (string) $row->name, 'amount' => Money::sub('0', Money::fromDatabase($row->amount), 2)];
        }

        // What a waiver forgave is what the fee still owed: its amount less what had been paid on it.
        $waived = LateFee::query()
            ->join('contracts', 'contracts.id', '=', 'late_fees.contract_id')
            ->join('customers', 'customers.id', '=', 'contracts.customer_id')
            ->where('late_fees.status', 'waived')
            ->where('late_fees.waived_at', '>=', $day->start)->where('late_fees.waived_at', '<', $day->end)
            ->orderBy('late_fees.waived_at')->orderBy('late_fees.id')
            ->toBase()->get(['late_fees.id', 'late_fees.amount', 'late_fees.paid_amount', 'customers.name']);
        foreach ($waived as $row) {
            $watch[] = ['kind' => 'waived_fee', 'id' => (string) $row->id, 'label' => (string) $row->name, 'amount' => Money::sub(Money::fromDatabase($row->amount), Money::fromDatabase($row->paid_amount), 2)];
        }

        // Recorded in this day (created_at) for a day before it (paid_at): that day's cash changed after it was counted.
        $backDated = Transaction::query()
            ->join('customers', 'customers.id', '=', 'transactions.customer_id')
            ->whereIn('transactions.type', self::PAYMENTS)
            ->where('transactions.created_at', '>=', $day->start)->where('transactions.created_at', '<', $day->end)
            ->where('transactions.paid_at', '<', $day->start)
            ->orderBy('transactions.created_at')->orderBy('transactions.paid_at')->orderBy('transactions.id')
            ->toBase()->get(['transactions.id', 'transactions.amount', 'customers.name']);
        foreach ($backDated as $row) {
            $watch[] = ['kind' => 'back_dated', 'id' => (string) $row->id, 'label' => (string) $row->name, 'amount' => Money::fromDatabase($row->amount)];
        }

        foreach (['opened' => 'created_at', 'cancelled' => 'cancelled_at'] as $kind => $column) {
            $contracts = Contract::query()
                ->join('customers', 'customers.id', '=', 'contracts.customer_id')
                ->where("contracts.{$column}", '>=', $day->start)->where("contracts.{$column}", '<', $day->end)
                ->orderBy("contracts.{$column}")->orderBy('contracts.number')
                ->toBase()->get(['contracts.id', 'contracts.principal', 'customers.name']);

            // A day that opened a great many (an import) shows them as one line, not a list to scroll past.
            if ($kind === 'opened' && $contracts->count() > self::OPENED_LISTED) {
                $watch[] = self::openedTogether($contracts);

                continue;
            }

            foreach ($contracts as $row) {
                $price = Money::fromDatabase($row->principal);
                $watch[] = ['kind' => $kind, 'id' => (string) $row->id, 'label' => (string) $row->name, 'amount' => Money::isZero($price) ? null : $price];
            }
        }

        return $watch;
    }

    /**
     * The contracts opened in a day as one line worth a look: how many, the first of them, and what they were sold for
     * together (null when none has a price, as for open accounts).
     *
     * @param  Collection<int, stdClass>  $contracts  the day's contracts opened, the first first
     * @return array{kind: 'opened', id: string, label: string, amount: ?string}
     */
    private static function openedTogether(Collection $contracts): array
    {
        $total = $contracts->reduce(fn (string $sum, stdClass $row): string => Money::add($sum, Money::fromDatabase($row->principal), 2), '0.00');

        return [
            'kind' => 'opened',
            'id' => (string) $contracts->first()->id,
            'label' => __(':count contracts opened', ['count' => $contracts->count()]),
            'amount' => Money::isZero($total) ? null : $total,
        ];
    }
}
