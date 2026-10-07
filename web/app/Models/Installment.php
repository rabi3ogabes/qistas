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
 * @property string $amount
 * @property string $paid_amount
 * @property string $status
 * @property Carbon|null $paid_at
 */
class Installment extends Model
{
    use BelongsToTenant, HasUuids;

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

    /** Past its due date and not fully paid. Due today is not overdue yet. */
    public function isOverdue(?CarbonInterface $today = null): bool
    {
        $today ??= today();

        return $this->status !== 'paid' && $this->due_date->lt($today->copy()->startOfDay());
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'paid_at' => 'datetime',
        ];
    }
}
