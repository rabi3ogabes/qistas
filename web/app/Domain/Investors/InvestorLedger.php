<?php

namespace App\Domain\Investors;

use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps each investor's money in step with the contracts they fund (Win Plan PP3, decision D7):
 *
 *   a contract opens    -> funding_out   = - the amount financed (the down payment never left the business)
 *   a payment comes in  -> principal_back + profit_share = the payment, profit counted as money comes in:
 *                          profit so far = round(paid so far x markup / total), so a contract paid in full has credited
 *                          exactly its markup, to the cent, however the payments were split
 *                          (a partner then passes its commission % of that profit to the main investor)
 *   a payment is voided -> the very same entries, negated
 *   a contract is cancelled -> funding_back = what of its principal had not come back yet
 *
 * Every method may be called again safely: what is already written is not written twice. Runs inside the caller's
 * transaction, with the caller's tenant.
 */
final class InvestorLedger
{
    public function fund(Contract $contract): void
    {
        if ($contract->investor_id === null || ! Money::isPositive($contract->financed)
            || $this->contractEntries($contract)->where('type', 'funding_out')->exists()) {
            return;
        }

        $this->write($contract->investor_id, 'funding_out', Money::sub('0', $contract->financed), $contract->start_date, $contract->id);
    }

    public function creditPayment(Transaction $payment): void
    {
        if ($payment->type !== 'payment') {
            return;
        }
        $contract = $this->fundedContract($payment->contract_id);
        if (InvestorEntry::query()->where('transaction_id', $payment->id)->exists()) {
            return;
        }
        $investor = Investor::query()->findOrFail($contract->investor_id);

        $amount = Money::add($payment->amount, '0', 2);
        $profitBefore = $this->sum($this->contractEntries($contract)->where('type', 'profit_share'));
        $paidBefore = $this->sum($this->contractEntries($contract)->whereIn('type', ['principal_back', 'profit_share']));

        $profitAfter = Money::isPositive($contract->total)
            ? Money::round(Money::div(Money::mul(Money::add($paidBefore, $amount), $contract->markup_amount, 6), $contract->total, 6), 2)
            : '0.00';
        if (Money::cmp($profitAfter, $contract->markup_amount) > 0) {
            $profitAfter = Money::add($contract->markup_amount, '0', 2);
        }
        $profit = $this->clamp(Money::sub($profitAfter, $profitBefore, 2), $amount);
        $principal = Money::sub($amount, $profit, 2);
        $on = $payment->paid_at;

        $this->write($investor->id, 'principal_back', $principal, $on, $contract->id, $payment->id);
        $this->write($investor->id, 'profit_share', $profit, $on, $contract->id, $payment->id);

        if (! $investor->is_main && Money::isPositive($investor->commission_percent) && Money::isPositive($profit)) {
            $commission = Money::round(Money::div(Money::mul($profit, $investor->commission_percent, 6), '100', 6), 2);
            $main = MainInvestor::for($investor->tenant);
            $this->write($investor->id, 'commission', Money::sub('0', $commission, 2), $on, $contract->id, $payment->id);
            $this->write($main->id, 'commission', $commission, $on, $contract->id, $payment->id);
        }
    }

    public function reverse(Transaction $reversal): void
    {
        if ($reversal->type !== 'reversal' || $reversal->reverses_transaction_id === null) {
            return;
        }
        $this->fundedContract($reversal->contract_id);
        if (InvestorEntry::query()->where('transaction_id', $reversal->id)->exists()) {
            return;
        }

        foreach (InvestorEntry::query()->where('transaction_id', $reversal->reverses_transaction_id)->whereNull('reverses_entry_id')->get() as $entry) {
            $this->write($entry->investor_id, $entry->type, Money::sub('0', $entry->amount, 2), $reversal->paid_at, $entry->contract_id, $reversal->id, reverses: $entry->id);
        }
    }

    public function releaseCancelled(Contract $contract): void
    {
        $contract = $this->fundedContract($contract->id);
        if ($contract->status !== 'cancelled' || $this->contractEntries($contract)->where('type', 'funding_back')->exists()) {
            return;
        }

        $out = Money::sub('0', $this->sum($this->contractEntries($contract)->whereIn('type', ['funding_out', 'principal_back'])), 2);
        if (Money::isPositive($out)) {
            $this->write($contract->investor_id, 'funding_back', $out, $contract->cancelled_at ?? now(), $contract->id);
        }
    }

    /**
     * Brings the workspace's books up to date for its main investor: every contract nobody funds becomes the main
     * investor's, and every contract, payment, reversal and cancellation gets the entries it should have, in the
     * order they happened (so profit counted as money came in comes out exactly as it would have live).
     */
    public function catchUp(Investor $main): void
    {
        Contract::query()->whereNull('investor_id')->update(['investor_id' => $main->id]);

        foreach (Contract::query()->orderBy('number')->get() as $contract) {
            $this->fund($contract);

            $transactions = Transaction::query()->where('contract_id', $contract->id)->whereIn('type', ['payment', 'reversal'])
                ->orderBy('created_at')->orderBy('id')->get();
            foreach ($transactions as $transaction) {
                $transaction->type === 'payment' ? $this->creditPayment($transaction) : $this->reverse($transaction);
            }

            $this->releaseCancelled($contract);
        }
    }

    /** The contract, with an investor: one made before investors existed is taken on by the main investor first. */
    private function fundedContract(string $id): Contract
    {
        $contract = Contract::query()->findOrFail($id);
        if ($contract->investor_id === null) {
            MainInvestor::for($contract->tenant);
            $contract = Contract::query()->findOrFail($id);
        }

        return $contract;
    }

    /** @return Builder<InvestorEntry> */
    private function contractEntries(Contract $contract): Builder
    {
        return InvestorEntry::query()->where('contract_id', $contract->id);
    }

    /** @param  Builder<InvestorEntry>  $query */
    private function sum(Builder $query): string
    {
        return Money::fromDatabase($query->sum('amount'));
    }

    /** Between zero and the payment: a profit share is never negative and never more than the money that came in. */
    private function clamp(string $profit, string $amount): string
    {
        if (Money::isNegative($profit)) {
            return '0.00';
        }

        return Money::cmp($profit, $amount) > 0 ? $amount : $profit;
    }

    private function write(string $investorId, string $type, string $amount, CarbonInterface|string $on, ?string $contractId = null, ?string $transactionId = null, ?string $reverses = null): void
    {
        if (Money::isZero($amount)) {
            return;
        }

        (new InvestorEntry)->forceFill([
            'investor_id' => $investorId,
            'type' => $type,
            'amount' => $amount,
            'contract_id' => $contractId,
            'transaction_id' => $transactionId,
            'reverses_entry_id' => $reverses,
            'occurred_on' => is_string($on) ? $on : $on->format('Y-m-d'),
        ])->save();
    }
}
