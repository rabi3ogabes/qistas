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
 * One fee for one period an instalment was late (Win Plan K4, decision D1): its own line, never added to the
 * instalment. Written once by App\Actions\ChargeLateFees; afterwards only payments (through the ledger) and a waiver
 * change it.
 *
 * @property string $contract_id
 * @property string $installment_id
 * @property int $period 1 for the first period late, 2 for the next, and so on
 * @property Carbon $charged_on the first day of that period, on the workspace's own calendar
 * @property string $amount
 * @property string $paid_amount
 * @property string $status open | paid | waived
 * @property bool $charity kept apart from the business's income
 * @property string|null $waived_reason
 * @property string|null $waived_by_user_id
 * @property Carbon|null $waived_at
 */
class LateFee extends Model
{
    use BelongsToTenant, HasUuids;

    /** What is still owed on it: nothing once it is paid or waived. */
    public function remaining(): string
    {
        return $this->status === 'open' ? Money::sub($this->amount, $this->paid_amount) : '0';
    }

    /**
     * Move paid_amount by $delta (positive for a payment, negative for its void). A waived fee stays waived; otherwise
     * it is paid once nothing is left. The only way a fee changes after it is charged, besides a waiver.
     */
    public function applyPayment(string $delta, CarbonInterface $at): void
    {
        $paid = Money::add($this->paid_amount, $delta);
        $status = match (true) {
            $this->status === 'waived' => 'waived',
            Money::cmp($paid, $this->amount) >= 0 => 'paid',
            default => 'open',
        };

        $this->forceFill(['paid_amount' => $paid, 'status' => $status])->save();
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'period' => 'integer',
            'charged_on' => 'date',
            'amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'charity' => 'boolean',
            'waived_at' => 'datetime',
        ];
    }
}
