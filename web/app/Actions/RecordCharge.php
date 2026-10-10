<?php

namespace App\Actions;

use App\Domain\Investors\InvestorLedger;
use App\Domain\Ledger\OpenAccount;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * "They took" (عليه) on an open contract (Win Plan PP4): a ledger line that adds to what the customer owes. Like a
 * payment it is never edited, only voided, and the same idempotency key returns the first line instead of adding
 * another. Past the credit limit it warns and never refuses: the shop knows its customer.
 */
final class RecordCharge
{
    public function __construct(private readonly CurrentTenant $current, private readonly InvestorLedger $investors) {}

    /**
     * @param  string  $amount  positive, at most two decimals
     * @param  ?string  $tag  one of Transaction::TAGS
     * @return array{transaction: Transaction, balance: string, over_credit_limit: bool}
     *
     * @throws ValidationException|FeatureUnavailable|FeatureLocked
     */
    public function handle(
        Contract $contract,
        string $amount,
        ?string $tag = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
        ?User $by = null,
        ?CarbonInterface $at = null,
    ): array {
        $amount = $this->validAmount($amount);
        if ($tag !== null && ! in_array($tag, Transaction::TAGS, true)) {
            throw ValidationException::withMessages(['tag' => __('Choose one of the tags offered.')]);
        }
        if ($at !== null && $at->isFuture()) {
            throw ValidationException::withMessages(['charged_at' => __('A line cannot be dated in the future.')]);
        }

        return $this->current->use($contract->tenant, function () use ($contract, $amount, $tag, $note, $idempotencyKey, $by, $at): array {
            Entitlements::for($contract->tenant)->assertEnabled(Feature::OpenContracts);

            if ($idempotencyKey !== null && ($existing = Transaction::where('idempotency_key', $idempotencyKey)->first()) !== null) {
                return $this->replay($existing, $contract, $amount);
            }

            try {
                return DB::transaction(function () use ($contract, $amount, $tag, $note, $idempotencyKey, $by, $at): array {
                    $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
                    if (! $locked->isOpen() || $locked->status !== 'active') {
                        throw ValidationException::withMessages(['contract' => __('Only a running open contract takes what the customer took.')]);
                    }

                    $line = (new Transaction)->forceFill([
                        'contract_id' => $locked->id,
                        'customer_id' => $locked->customer_id,
                        'type' => 'charge',
                        'method' => 'other',
                        'amount' => $amount,
                        'paid_at' => $at ?? now(),
                        'note' => $note,
                        'tag' => $tag,
                        'idempotency_key' => $idempotencyKey,
                        'created_by_user_id' => $by?->id,
                    ]);
                    $line->save();

                    // Goods out on credit are money out of whoever funds the contract.
                    $this->investors->fundCharge($line);

                    return $this->result($locked, $line);
                });
            } catch (UniqueConstraintViolationException $e) {
                $existing = $idempotencyKey === null ? null : Transaction::where('idempotency_key', $idempotencyKey)->first();

                return $existing !== null ? $this->replay($existing, $contract, $amount) : throw $e;
            }
        });
    }

    /** @return array{transaction: Transaction, balance: string, over_credit_limit: bool} */
    private function result(Contract $contract, Transaction $line): array
    {
        $balance = OpenAccount::balance($contract);

        return [
            'transaction' => $line,
            'balance' => $balance,
            'over_credit_limit' => $contract->credit_limit !== null && Money::cmp($balance, $contract->credit_limit) > 0,
        ];
    }

    /**
     * The same key must mean the same charge; anything else is a client mistake, not a retry.
     *
     * @return array{transaction: Transaction, balance: string, over_credit_limit: bool}
     */
    private function replay(Transaction $existing, Contract $contract, string $amount): array
    {
        if ($existing->type !== 'charge' || $existing->contract_id !== $contract->id || Money::cmp($existing->amount, $amount) !== 0) {
            throw ValidationException::withMessages(['idempotency_key' => __('This key was already used for a different line.')]);
        }

        return $this->result(Contract::query()->findOrFail($contract->id), $existing);
    }

    private function validAmount(string $amount): string
    {
        try {
            $parsed = Money::parse($amount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Enter a valid amount.')]);
        }
        if (! Money::isPositive($parsed) || Money::decimals($parsed) > 2) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount greater than zero, with at most two decimals.')]);
        }

        return Money::add($parsed, '0', 2);
    }
}
