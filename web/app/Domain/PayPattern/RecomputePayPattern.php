<?php

namespace App\Domain\PayPattern;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\CustomerPayPattern;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\WorkspaceDay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps each customer's stored payday pattern current (daily-habit wave A, H6): reads the dates they paid on from the
 * ledger, hands them to PayPatternCalculator and stores what comes back, replacing the customer's row, or removing it when
 * there is nothing to say. Only reads the ledger; the pattern is derived from it and never an input to it.
 *
 * What counts as "when they paid": an untagged `payment` (an advance, a refund, an early-payment discount or an unpaid
 * mark says nothing about the customer's payday, and a down payment, a charge or a reversal is not an instalment payment)
 * on a contract that is monthly and not an open account, that has not been reversed by a void, in the last 18 calendar
 * months. A cancelled contract's payments still show when the customer paid, so they count.
 *
 * It runs after a payment or a void (through afterCommit(), which never lets a failure reach the person who took the
 * money) and every night for every workspace (forWorkspace(), from `qistas:learn-paydays`). Both ask the feature's switch
 * at the moment they run, so a workspace that has it off is left exactly as it is.
 */
final class RecomputePayPattern
{
    /**
     * How far back the ledger is read, in calendar months of the workspace's own calendar, counting the current one. The
     * calculator uses the last twelve months that have a payment; a customer who skips a month now and then needs a little
     * more than twelve calendar months to supply twelve paid ones, so this reaches back eighteen. Nothing older is read, so
     * a customer who stopped paying years ago keeps no pattern from those days.
     */
    private const WINDOW_MONTHS = 18;

    /** How many customers are worked out together: one query for their payments, one write, one removal. */
    private const CHUNK = 200;

    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * Work out [$customerId]'s pattern again once the transaction that is writing the ledger commits (at once when there is
     * none). Anything that goes wrong is reported and swallowed: a calculation problem must never fail or delay a payment
     * or a void, which by then are written.
     */
    public static function afterCommit(Tenant $tenant, string $customerId): void
    {
        DB::afterCommit(static fn () => self::swallow(static function () use ($tenant, $customerId): void {
            $customer = app(CurrentTenant::class)->use($tenant, fn () => Customer::withTrashed()->find($customerId));
            if ($customer !== null) {
                app(self::class)->handle($customer);
            }
        }));
    }

    /** Runs [$work] and reports, rather than throws, whatever goes wrong in it. */
    private static function swallow(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Work out [$customer]'s pattern again, in the customer's own workspace whichever one is current. Does nothing when
     * the workspace has the feature off: no payment is read, nothing is written or deleted.
     */
    public function handle(Customer $customer): void
    {
        $tenant = $customer->tenant;

        $this->current->use($tenant, function () use ($tenant, $customer): void {
            if ($this->enabled($tenant)) {
                $this->refresh($tenant, [$customer]);
            }
        });
    }

    /**
     * Work out the pattern of every customer of [$tenant] who has a payment that counts, and remove the stored pattern of
     * any customer who no longer qualifies (every payment voided or older than the window, or the customer deleted). A
     * customer is worked out from nothing but their own payments, so running it again changes nothing but `computed_at`.
     *
     * @return int how many patterns the workspace has stored for the customers it looked at; 0 when the feature is off
     */
    public function forWorkspace(Tenant $tenant): int
    {
        return $this->current->use($tenant, function () use ($tenant): int {
            if (! $this->enabled($tenant)) {
                return 0;
            }

            // Those with something to learn from, and those with a stored row that may no longer be right. Ids only.
            $ids = $this->payments($tenant)->distinct()->pluck('customer_id')
                ->merge(CustomerPayPattern::query()->pluck('customer_id'))->unique()->values()->all();

            $stored = 0;
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $stored += $this->refresh($tenant, Customer::withTrashed()->whereIn('id', $chunk)->get()->values()->all());
            }

            return $stored;
        });
    }

    private function enabled(Tenant $tenant): bool
    {
        // Asked at every run, before anything is read: the platform's switch and the plan can change at any time.
        return Entitlements::for($tenant)->check(Feature::PaydayLearning)->enabled();
    }

    /**
     * Store the pattern of each of [$customers] (all of [$tenant]) from one read of their payments, and remove the rows of
     * those with nothing to say. A deleted customer has none.
     *
     * @param  list<Customer>  $customers
     * @return int how many rows were stored
     */
    private function refresh(Tenant $tenant, array $customers): int
    {
        // A deleted customer is given no dates, so there is nothing to say about them.
        $live = array_values(array_map(fn (Customer $customer) => $customer->id, array_filter($customers, fn (Customer $customer) => ! $customer->trashed())));
        $paidOn = $live === [] ? [] : $this->paidOn($tenant, $live);

        $rows = [];
        $remove = [];
        foreach ($customers as $customer) {
            $pattern = PayPatternCalculator::calculate($paidOn[$customer->id] ?? []);
            if ($pattern === null) {
                $remove[] = $customer->id;

                continue;
            }
            $rows[] = [
                // Used only for a customer who has no row yet: a row that exists keeps its own id.
                'id' => (new CustomerPayPattern)->newUniqueId(),
                // From the customer's workspace, never from anything a request said.
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'day' => $pattern->day,
                'spread' => $pattern->spread,
                'months' => $pattern->months,
                'within' => $pattern->within,
                'confidence' => $pattern->confidence,
                'computed_at' => now(),
            ];
        }

        // One statement that inserts or replaces, so two runs at once (a payment and the night's run) cannot clash on the
        // one row a customer may have.
        if ($rows !== []) {
            CustomerPayPattern::query()->upsert($rows, ['tenant_id', 'customer_id'], ['day', 'spread', 'months', 'within', 'confidence', 'computed_at']);
        }
        if ($remove !== []) {
            CustomerPayPattern::query()->whereIn('customer_id', $remove)->delete();
        }

        return count($rows);
    }

    /**
     * The dates [$customerIds] paid on, as they fall on the workspace's own calendar (what the calculator is given), from
     * one query for all of them.
     *
     * @param  list<string>  $customerIds
     * @return array<string, list<CarbonImmutable>> by customer id
     */
    private function paidOn(Tenant $tenant, array $customerIds): array
    {
        $dates = [];
        foreach ($this->payments($tenant)->whereIn('customer_id', $customerIds)->get(['customer_id', 'paid_at']) as $payment) {
            // A copy: localTime() moves the zone of the moment it is handed, and a cast date is the model's own.
            $dates[$payment->customer_id][] = CarbonImmutable::instance($tenant->localTime(CarbonImmutable::instance($payment->paid_at)));
        }

        return $dates;
    }

    /**
     * The payments that tell when a customer pays (see the class comment), for the current workspace.
     *
     * @return Builder<Transaction>
     */
    private function payments(Tenant $tenant): Builder
    {
        return Transaction::query()
            ->where('type', 'payment')
            ->whereNull('tag')
            ->where('paid_at', '>=', $this->windowStart($tenant))
            ->whereHas('contract', fn (Builder $contract) => $contract->where('type', '<>', 'open')->where('frequency', 'monthly'))
            // Not one a void has reversed: the reversal row points back at the payment it cancels.
            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('transactions as reversals')
                ->whereColumn('reversals.reverses_transaction_id', 'transactions.id'));
    }

    /**
     * The first instant of the window: 00:00 on the 1st of the month WINDOW_MONTHS - 1 before the workspace's current one,
     * on the workspace's own clock, as a UTC instant. Whole months, because the calculator takes the first payment of each
     * month: a window that began mid-month would take the oldest month's second payment for its first.
     */
    private function windowStart(Tenant $tenant): CarbonImmutable
    {
        $first = CarbonImmutable::parse(WorkspaceDay::of($tenant)->date, 'UTC')->startOfMonth()->subMonthsNoOverflow(self::WINDOW_MONTHS - 1);

        return WorkspaceDay::on($tenant, $first->format('Y-m-d'))->start;
    }
}
