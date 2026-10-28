<?php

namespace App\Actions;

use App\Domain\DayClose\DayCloseGuard;
use App\Domain\Investors\InvestorLedger;
use App\Domain\LateFees\LateFees;
use App\Domain\Ledger\ContractSettlement;
use App\Domain\Ledger\PaymentAllocator;
use App\Domain\Settlement\EarlySettlementQuote;
use App\Domain\Settlement\QuoteChanged;
use App\Models\Contract;
use App\Models\ContractAdjustment;
use App\Models\Installment;
use App\Models\LateFee;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Takes a payment against a contract: writes the ledger line, applies it to what is due (the oldest instalments, then
 * the late fees, then what is not due yet), and settles the contract when nothing is left. The one place payments are
 * recorded.
 *
 * Safe to retry: the same idempotency key returns the original transaction instead of adding another.
 */
final class RecordPayment
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly PaymentAllocator $allocator,
        private readonly InvestorLedger $investors,
    ) {}

    /**
     * @param  string  $amount  positive, at most two decimals, not more than is still owed
     * @param  ?string  $idempotencyKey  1-100 characters from A-Z a-z 0-9 _ . : -
     * @param  ?CarbonInterface  $paidAt  when the money was received (default now; never in the future)
     * @param  ?string  $tag  one of Transaction::TAGS (an advance, a refund, an early-payment discount)
     * @param  ?string  $applyTo  the instalment to fill first (Win Plan PP6); the rest goes to the oldest
     * @param  ?string  $batchId  the payment across contracts this line is part of (Win Plan PP6). Only
     *                            RecordCustomerPayment passes one, and only after it has put the whole payment to the
     *                            after-close rule (DayCloseGuard) once, before writing anything: a line with a batch id
     *                            is not asked again, so one payment makes one audit row. Another caller must not pass one.
     *                            It cannot invent one either: the id is a foreign key to `payment_batches`, and
     *                            RecordCustomerPayment is the only code that writes that table, after its guard
     *
     * @throws ValidationException naming the field at fault (`paid_at` when it is back-dated into a closed day and [$by]
     *                             is not an owner or a manager: see DayCloseGuard)
     */
    public function handle(
        Contract $contract,
        string $amount,
        string $method,
        ?string $idempotencyKey = null,
        ?User $by = null,
        ?string $note = null,
        ?CarbonInterface $paidAt = null,
        ?string $tag = null,
        ?string $applyTo = null,
        ?string $batchId = null,
    ): Transaction {
        $this->validateFormat($amount, $method, $idempotencyKey, $paidAt, $tag);
        $amount = Money::add(Money::parse($amount), '0', 2);
        $paidAt ??= now();

        return $this->current->use($contract->tenant, function () use ($contract, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $tag, $applyTo, $batchId): Transaction {
            if ($idempotencyKey !== null && ($existing = Transaction::where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $this->replay($existing, $contract, $amount, $method);
            }

            try {
                return DB::transaction(function () use ($contract, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $tag, $applyTo, $batchId): Transaction {
                    // A closed day is changed only by an owner or a manager (D10), and asked before anything is written.
                    if ($batchId === null) {
                        DayCloseGuard::assertMayChange($contract->tenant, $paidAt, $by);
                    }

                    return $this->record($contract, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $tag, $applyTo, $batchId);
                });
            } catch (UniqueConstraintViolationException $e) {
                // Two requests with the same key arrived together and the other one won: answer with its result.
                $existing = $idempotencyKey === null ? null : Transaction::where('idempotency_key', $idempotencyKey)->first();

                return $existing !== null ? $this->replay($existing, $contract, $amount, $method) : throw $e;
            }
        });
    }

    /**
     * Settles a contract early (Win Plan PP6): takes the payoff, waives what the rebate covers, writes one adjustment
     * line and settles the contract, all at once. The payoff must be the one the person was shown; if money came in
     * meanwhile it has changed, and nothing is written. Safe to retry with the same key.
     *
     * @throws QuoteChanged when [$expectedPayoff] is no longer the payoff
     * @throws ValidationException when the contract cannot be settled early, or the rebate cannot be read, or on `paid_at`
     *                             when it is back-dated into a closed day and [$by] is not an owner or a manager
     */
    public function settle(
        Contract $contract,
        string $rebateType,
        string $rebateValue,
        string $expectedPayoff,
        string $method,
        ?string $idempotencyKey = null,
        ?User $by = null,
        ?string $note = null,
        ?CarbonInterface $paidAt = null,
    ): Transaction|ContractAdjustment {
        $this->validateFormat('1', $method, $idempotencyKey, $paidAt);
        $paidAt ??= now();

        return $this->current->use($contract->tenant, function () use ($contract, $rebateType, $rebateValue, $expectedPayoff, $method, $idempotencyKey, $by, $note, $paidAt): Transaction|ContractAdjustment {
            if ($idempotencyKey !== null && ($existing = Transaction::where('idempotency_key', $idempotencyKey)->first()) !== null) {
                if ($existing->contract_id !== $contract->id || $existing->tag !== 'early_discount') {
                    throw ValidationException::withMessages(['idempotency_key' => __('This key was already used for a different payment.')]);
                }

                return $existing;
            }

            return DB::transaction(function () use ($contract, $rebateType, $rebateValue, $expectedPayoff, $method, $idempotencyKey, $by, $note, $paidAt): Transaction|ContractAdjustment {
                $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'active' || $locked->isOpen()) {
                    throw ValidationException::withMessages(['contract' => $locked->status === 'settled'
                        ? __('Nothing is owed on this contract.')
                        : __('Only a running contract with a schedule can be settled early.')]);
                }

                $installments = Installment::query()->where('contract_id', $locked->id)->lockForUpdate()->orderBy('number')->get();
                $fees = LateFees::open([$locked->id], lock: true);
                try {
                    $quote = EarlySettlementQuote::for($locked, $installments, $rebateType, $rebateValue, LateFees::owed($locked));
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['rebate_value' => $e->getMessage()]);
                }
                if (Money::cmp($quote->payoff, Money::parse($expectedPayoff)) !== 0) {
                    throw new QuoteChanged($quote);
                }

                $transaction = null;
                if (Money::isPositive($quote->payoff)) {
                    // Money comes in: into a closed day it is an owner's or a manager's (D10), asked before anything is
                    // written. A payoff of nothing brings no cash, so it changes no closed day and is not asked.
                    DayCloseGuard::assertMayChange($contract->tenant, $paidAt, $by);

                    // Every late fee is paid in full: the rebate comes off the profit on instalments, never off a fee.
                    $allocations = $this->allocator->allocateWithFees($this->rows($installments), LateFees::rows($fees), $quote->payoff, $paidAt->format('Y-m-d'), feesFirst: true);
                    $transaction = $this->write($locked, 'payment', $quote->payoff, $method, $idempotencyKey, $by, $note, $paidAt, 'early_discount');
                    $this->apply($transaction, $installments, $allocations['installments'], $paidAt);
                    LateFees::apply($transaction, $fees, $allocations['fees'], $paidAt);
                }

                // Whatever is still owed after the payoff is what the rebate covers: waived, with what each still owed.
                $waived = [];
                foreach ($installments as $installment) {
                    $installment->refresh();
                    if (! in_array($installment->status, Installment::CLOSED, true)) {
                        $waived[] = ['installment_id' => $installment->id, 'amount' => Money::add($installment->remaining(), '0', 2)];
                        $installment->forceFill(['status' => 'waived'])->save();
                    }
                }

                $adjustment = null;
                if (Money::isPositive($quote->rebate)) {
                    $adjustment = (new ContractAdjustment)->forceFill([
                        'contract_id' => $locked->id,
                        'type' => 'early_rebate',
                        'amount' => $quote->rebate,
                        'reason' => $note,
                        'transaction_id' => $transaction?->id,
                        'details' => ['waived' => $waived],
                        'created_by_user_id' => $by?->id,
                    ]);
                    $adjustment->save();
                }

                ContractSettlement::sync($locked, $paidAt);
                if ($transaction !== null) {
                    // Every cent financed goes back to the investor; the rebate comes out of profit only (Win Plan PP3).
                    $this->investors->creditPayment($transaction);
                }
                Audit::record('contract.settled_early', $locked, ['payoff' => $quote->payoff, 'rebate' => $quote->rebate], $locked->tenant_id, $by?->id);

                return $transaction ?? $adjustment ?? throw ValidationException::withMessages(['contract' => __('Nothing is owed on this contract.')]);
            });
        });
    }

    private function record(Contract $contract, string $amount, string $method, ?string $key, ?User $by, ?string $note, CarbonInterface $paidAt, ?string $tag, ?string $applyTo = null, ?string $batchId = null): Transaction
    {
        // Payments on one contract take turns: the second waits here and then sees the first one's effect.
        $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

        if ($locked->status === 'cancelled') {
            throw ValidationException::withMessages(['contract' => __('This contract was cancelled and cannot take payments.')]);
        }

        // An open contract takes money on account: no instalments to pay, and paying ahead leaves the customer credit.
        if ($locked->isOpen()) {
            $transaction = $this->write($locked, 'payment', $amount, $method, $key, $by, $note, $paidAt, $tag, $batchId);
            $this->investors->creditPayment($transaction);

            return $transaction;
        }

        $installments = Installment::query()->where('contract_id', $locked->id)->lockForUpdate()->get();
        $fees = LateFees::open([$locked->id], lock: true);
        $owed = $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->remaining()), '0');
        $owed = $fees->reduce(fn (string $carry, LateFee $fee) => Money::add($carry, $fee->remaining()), $owed);

        if (Money::cmp($amount, $owed) > 0) {
            throw ValidationException::withMessages(['amount' => Money::isZero($owed)
                ? __('Nothing is owed on this contract.')
                : __('The payment is more than the :owed still owed on this contract.', ['owed' => Money::add($owed, '0', 2)])]);
        }

        // The instalment the person chose (Win Plan PP6) must be this contract's, and still owe something.
        if ($applyTo !== null) {
            $chosen = $installments->firstWhere('id', $applyTo);
            if ($chosen === null || ! Money::isPositive($chosen->remaining())) {
                throw ValidationException::withMessages(['apply_to' => __('Choose an instalment of this contract that still owes something.')]);
            }
        }

        // What is already due first, then the late fees, then what is not due yet (Win Plan K4, decision D3).
        try {
            $allocations = $this->allocator->allocateWithFees($this->rows($installments), LateFees::rows($fees), $amount, $paidAt->format('Y-m-d'), $applyTo);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $transaction = $this->write($locked, 'payment', $amount, $method, $key, $by, $note, $paidAt, $tag, $batchId);
        $this->apply($transaction, $installments, $allocations['installments'], $paidAt);
        LateFees::apply($transaction, $fees, $allocations['fees'], $paidAt);

        ContractSettlement::sync($locked, $paidAt);
        // Its principal and profit go to whoever funded the contract, in the same transaction (Win Plan PP3).
        $this->investors->creditPayment($transaction);

        return $transaction;
    }

    /**
     * @param  Collection<int, Installment>  $installments
     * @return list<array{id: string, number: int, due_date: string, remaining: string}>
     */
    private function rows($installments): array
    {
        return $installments->map(fn (Installment $i) => [
            'id' => $i->id, 'number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'remaining' => $i->remaining(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Installment>  $installments
     * @param  list<array{installment_id: string, amount: string}>  $allocations
     */
    private function apply(Transaction $transaction, $installments, array $allocations, CarbonInterface $paidAt): void
    {
        $byId = $installments->keyBy('id');
        foreach ($allocations as $allocation) {
            $byId[$allocation['installment_id']]->applyPayment($allocation['amount'], $paidAt);
            (new TransactionAllocation)->forceFill([
                'transaction_id' => $transaction->id,
                'installment_id' => $allocation['installment_id'],
                'amount' => $allocation['amount'],
            ])->save();
        }
    }

    private function write(Contract $contract, string $type, string $amount, string $method, ?string $key, ?User $by, ?string $note, CarbonInterface $paidAt, ?string $tag, ?string $batchId = null): Transaction
    {
        $transaction = (new Transaction)->forceFill([
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'type' => $type,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'note' => $note,
            'tag' => $tag,
            'idempotency_key' => $key,
            'created_by_user_id' => $by?->id,
            'batch_id' => $batchId,
        ]);
        $transaction->save();

        return $transaction;
    }

    /** The same key must mean the same payment; anything else is a client mistake, not a retry. */
    private function replay(Transaction $existing, Contract $contract, string $amount, string $method): Transaction
    {
        $samePayment = $existing->type === 'payment'
            && $existing->contract_id === $contract->id
            && $existing->method === $method
            && Money::cmp($existing->amount, $amount) === 0;

        if (! $samePayment) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('This key was already used for a different payment.'),
            ]);
        }

        return $existing;
    }

    private function validateFormat(string $amount, string $method, ?string $key, ?CarbonInterface $paidAt, ?string $tag = null): void
    {
        $errors = [];

        try {
            $parsed = Money::parse($amount);
            if (! Money::isPositive($parsed)) {
                $errors['amount'] = __('The amount must be greater than zero.');
            } elseif (Money::decimals($parsed) > 2) {
                $errors['amount'] = __('The amount can have at most two decimals.');
            }
        } catch (InvalidArgumentException) {
            $errors['amount'] = __('Enter a valid amount.');
        }

        if (! in_array($method, config('qistas.payment_methods'), true)) {
            $errors['method'] = __('Choose how the payment was made.');
        }
        if ($paidAt !== null && $paidAt->isFuture()) {
            $errors['paid_at'] = __('A payment cannot be dated in the future.');
        }
        if ($tag !== null && ! in_array($tag, Transaction::TAGS, true)) {
            $errors['tag'] = __('Choose one of the tags offered.');
        }
        if ($key !== null && ! preg_match('/^[A-Za-z0-9_.:\-]{1,100}$/', $key)) {
            $errors['idempotency_key'] = __('The idempotency key must be 1 to 100 letters, digits or _ . : -');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
