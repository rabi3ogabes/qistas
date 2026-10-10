<?php

namespace App\Actions;

use App\Domain\Investors\InvestorLedger;
use App\Domain\Ledger\ContractSettlement;
use App\Domain\Ledger\PaymentAllocator;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Takes a payment against a contract: writes the ledger line, applies it to the oldest instalments first,
 * and settles the contract when nothing is left. The one place payments are recorded.
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
     *
     * @throws ValidationException naming the field at fault
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
    ): Transaction {
        $this->validateFormat($amount, $method, $idempotencyKey, $paidAt, $tag);
        $amount = Money::add(Money::parse($amount), '0', 2);
        $paidAt ??= now();

        return $this->current->use($contract->tenant, function () use ($contract, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $tag): Transaction {
            if ($idempotencyKey !== null && ($existing = Transaction::where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $this->replay($existing, $contract, $amount, $method);
            }

            try {
                return DB::transaction(fn () => $this->record($contract, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $tag));
            } catch (UniqueConstraintViolationException $e) {
                // Two requests with the same key arrived together and the other one won: answer with its result.
                $existing = $idempotencyKey === null ? null : Transaction::where('idempotency_key', $idempotencyKey)->first();

                return $existing !== null ? $this->replay($existing, $contract, $amount, $method) : throw $e;
            }
        });
    }

    private function record(Contract $contract, string $amount, string $method, ?string $key, ?User $by, ?string $note, CarbonInterface $paidAt, ?string $tag): Transaction
    {
        // Payments on one contract take turns: the second waits here and then sees the first one's effect.
        $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

        if ($locked->status === 'cancelled') {
            throw ValidationException::withMessages(['contract' => __('This contract was cancelled and cannot take payments.')]);
        }

        // An open contract takes money on account: no instalments to pay, and paying ahead leaves the customer credit.
        if ($locked->isOpen()) {
            $transaction = $this->write($locked, 'payment', $amount, $method, $key, $by, $note, $paidAt, $tag);
            $this->investors->creditPayment($transaction);

            return $transaction;
        }

        $installments = Installment::query()->where('contract_id', $locked->id)->lockForUpdate()->get();
        $owed = $installments->reduce(fn (string $carry, Installment $i) => Money::add($carry, $i->remaining()), '0');

        if (Money::cmp($amount, $owed) > 0) {
            throw ValidationException::withMessages(['amount' => Money::isZero($owed)
                ? __('Nothing is owed on this contract.')
                : __('The payment is more than the :owed still owed on this contract.', ['owed' => Money::add($owed, '0', 2)])]);
        }

        try {
            $allocations = $this->allocator->allocate(
                $installments->map(fn (Installment $i) => [
                    'id' => $i->id, 'number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'remaining' => $i->remaining(),
                ])->all(),
                $amount,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $transaction = $this->write($locked, 'payment', $amount, $method, $key, $by, $note, $paidAt, $tag);

        $byId = $installments->keyBy('id');
        foreach ($allocations as $allocation) {
            $byId[$allocation['installment_id']]->applyPayment($allocation['amount'], $paidAt);
            (new TransactionAllocation)->forceFill([
                'transaction_id' => $transaction->id,
                'installment_id' => $allocation['installment_id'],
                'amount' => $allocation['amount'],
            ])->save();
        }

        ContractSettlement::sync($locked, $paidAt);
        // Its principal and profit go to whoever funded the contract, in the same transaction (Win Plan PP3).
        $this->investors->creditPayment($transaction);

        return $transaction;
    }

    private function write(Contract $contract, string $type, string $amount, string $method, ?string $key, ?User $by, ?string $note, CarbonInterface $paidAt, ?string $tag): Transaction
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
