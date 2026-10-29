<?php

namespace App\Actions;

use App\Domain\DayClose\DayCloseGuard;
use App\Domain\LateFees\LateFees;
use App\Domain\Ledger\PaymentAllocator;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\PaymentBatch;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One payment from a customer across their contracts (Win Plan PP6): the oldest instalment due first whichever contract
 * it belongs to, or a split chosen by hand. Each contract gets its own ledger line through RecordPayment, and the lines
 * are tied together as one batch. All or nothing; safe to retry with the same key.
 */
final class RecordCustomerPayment
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly RecordPayment $record,
        private readonly PaymentAllocator $allocator,
    ) {}

    /**
     * @param  list<array{contract_id: string, amount: string}>|null  $split  chosen by hand; null pays the oldest due first
     *
     * @throws ValidationException naming the field at fault (`paid_at` when it is back-dated into a closed day and [$by]
     *                             is not an owner or a manager: see DayCloseGuard)
     */
    public function handle(
        Customer $customer,
        string $amount,
        string $method,
        ?string $idempotencyKey = null,
        ?User $by = null,
        ?string $note = null,
        ?CarbonInterface $paidAt = null,
        ?array $split = null,
    ): PaymentBatch {
        $amount = Money::add(Money::parse($amount), '0', 2);

        return $this->current->use($customer->tenant, function () use ($customer, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $split): PaymentBatch {
            if ($idempotencyKey !== null && ($existing = PaymentBatch::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
                if ($existing->customer_id !== $customer->id || Money::cmp($existing->amount, $amount) !== 0) {
                    throw ValidationException::withMessages(['idempotency_key' => __('This key was already used for a different payment.')]);
                }

                return $existing;
            }

            return DB::transaction(function () use ($customer, $amount, $method, $idempotencyKey, $by, $note, $paidAt, $split): PaymentBatch {
                // A closed day is changed only by an owner or a manager (D10): asked once for the whole payment, before
                // anything is written; its lines (RecordPayment with the batch) are not asked again.
                DayCloseGuard::assertMayChange($customer->tenant, $paidAt ?? now(), $by);

                $portions = $split === null ? $this->oldestFirst($customer, $amount, $paidAt) : $this->byHand($customer, $amount, $split);

                $batch = (new PaymentBatch)->forceFill([
                    'customer_id' => $customer->id,
                    'amount' => $amount,
                    'method' => $method,
                    'idempotency_key' => $idempotencyKey,
                    'created_by_user_id' => $by?->id,
                ]);
                $batch->save();

                foreach ($portions as $contractId => $portion) {
                    $this->record->handle(
                        Contract::query()->findOrFail($contractId), $portion, $method,
                        $idempotencyKey === null ? null : substr($idempotencyKey, 0, 60).':'.substr($contractId, 0, 36),
                        $by, $note, $paidAt, null, null, $batch->id,
                    );
                }

                return $batch;
            });
        });
    }

    /**
     * The oldest instalment due first, across the customer's running contracts with a schedule, then the late fees,
     * then what is not due yet.
     *
     * @return array<string, string> amount by contract id, in the order they are paid
     */
    private function oldestFirst(Customer $customer, string $amount, ?CarbonInterface $paidAt = null): array
    {
        $contracts = Contract::query()->where('customer_id', $customer->id)->where('status', 'active')->where('type', '!=', 'open')->get()->keyBy('id');
        $rows = Installment::query()->whereIn('contract_id', $contracts->keys())->whereNotIn('status', Installment::CLOSED)->get()
            ->map(fn (Installment $i) => [
                'id' => $i->id,
                // Ties on the same day go to the older contract first.
                'number' => $contracts[$i->contract_id]->number * 10000 + $i->number,
                'due_date' => $i->due_date->format('Y-m-d'),
                'remaining' => $i->remaining(),
                'contract_id' => $i->contract_id,
            ])->values()->all();

        // Late fees in the same order as on one contract (Win Plan K4): after what is due, before what is not.
        $fees = LateFees::open($contracts->keys()->all());
        $feeContract = $fees->pluck('contract_id', 'id')->all();
        $feeRows = array_map(fn (array $row) => [...$row, 'number' => $contracts[$feeContract[$row['id']]]->number * 10000 + $row['number']], LateFees::rows($fees));

        $owed = array_reduce($rows, fn (string $sum, array $row) => Money::add($sum, $row['remaining']), '0');
        $owed = array_reduce($feeRows, fn (string $sum, array $row) => Money::add($sum, $row['remaining']), $owed);
        if (Money::cmp($amount, $owed) > 0) {
            throw ValidationException::withMessages(['amount' => __('The payment is more than the :owed this customer still owes.', ['owed' => Money::add($owed, '0', 2)])]);
        }

        try {
            $allocations = $this->allocator->allocateWithFees($rows, $feeRows, $amount, ($paidAt ?? now())->format('Y-m-d'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $contractOf = array_column($rows, 'contract_id', 'id') + $feeContract;
        $portions = [];
        foreach ([...$allocations['installments'], ...$allocations['fees']] as $allocation) {
            $contractId = $contractOf[$allocation['installment_id'] ?? $allocation['late_fee_id']];
            $portions[$contractId] = Money::add($portions[$contractId] ?? '0', $allocation['amount'], 2);
        }

        return $portions;
    }

    /**
     * @param  list<array{contract_id: string, amount: string}>  $split
     * @return array<string, string>
     */
    private function byHand(Customer $customer, string $amount, array $split): array
    {
        $portions = [];
        foreach ($split as $part) {
            $contract = Contract::query()->whereKey($part['contract_id'])->where('customer_id', $customer->id)->first();
            if ($contract === null || $contract->status !== 'active') {
                throw ValidationException::withMessages(['split' => __('Every part must go to a running contract of this customer.')]);
            }
            $portions[$contract->id] = Money::add($portions[$contract->id] ?? '0', Money::parse($part['amount']), 2);
        }

        $total = array_reduce($portions, fn (string $sum, string $part) => Money::add($sum, $part, 2), '0');
        if (Money::cmp($total, $amount) !== 0) {
            throw ValidationException::withMessages(['split' => __('The parts must add up to the payment.')]);
        }

        return $portions;
    }
}
