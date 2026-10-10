<?php

namespace App\Models;

use App\Support\Money;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled payment of a contract. Created with the contract; afterwards only payments change
 * paid_amount, status and paid_at (through the ledger).
 *
 * @property int $number
 * @property Carbon $due_date
 * @property Carbon|null $grace_until the last day it can be paid without being late: the due date plus the contract's grace days
 * @property string $amount
 * @property string $paid_amount
 * @property string $status
 * @property Carbon|null $paid_at
 */
class Installment extends Model
{
    use BelongsToTenant, HasUuids;

    protected static function booted(): void
    {
        // Lateness queries read grace_until; an instalment written without it has no grace (late the day after it is due).
        static::creating(function (Installment $installment): void {
            $installment->grace_until ??= $installment->due_date;
        });
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** What is still owed on this instalment. */
    public function remaining(): string
    {
        return Money::sub($this->amount, $this->paid_amount);
    }

    /**
     * Move paid_amount by $delta (positive for a payment, negative for a reversal) and bring status and
     * paid_at into line. The only way an instalment changes after the contract is opened.
     */
    public function applyPayment(string $delta, CarbonInterface $at): void
    {
        $paid = Money::add($this->paid_amount, $delta);
        $status = match (true) {
            Money::cmp($paid, $this->amount) >= 0 => 'paid',
            Money::isZero($paid) => 'pending',
            default => 'partial',
        };

        $this->forceFill([
            'paid_amount' => $paid,
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($this->paid_at ?? $at) : null,
        ])->save();
    }

    /**
     * Not fully paid and past its last day of grace (`grace_until`: the due date plus the contract's grace days; the due
     * date itself when there are none). Due today, or still within the grace days, is not overdue yet.
     */
    public function isOverdue(?CarbonInterface $today = null): bool
    {
        $today ??= today();

        return $this->status !== 'paid' && ($this->grace_until ?? $this->due_date)->lt($today->copy()->startOfDay());
    }

    /** What a person sees: paid, overdue (past its date), partial (part-paid, not yet due) or upcoming. */
    public function displayState(?CarbonInterface $today = null): string
    {
        return match (true) {
            $this->status === 'paid' => 'paid',
            $this->isOverdue($today) => 'overdue',
            $this->status === 'partial' => 'partial',
            default => 'upcoming',
        };
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_date' => 'date',
            'grace_until' => 'date',
            'amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'paid_at' => 'datetime',
        ];
    }
}
