<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An agreement to repay a purchase in instalments (or a cash sale, which is a single instalment).
 *
 * Nothing is mass-assignable: contracts are written only by App\Actions\CreateContract, and afterwards only
 * their status changes (settled by the ledger, cancelled by an authorised person). The schedule is fixed.
 *
 * @property int $number
 * @property string $type
 * @property string $status
 * @property string $principal
 * @property string $down_payment
 * @property string $financed
 * @property string $markup_amount
 * @property string $total
 * @property Carbon $start_date
 * @property Carbon $first_due_date
 */
class Contract extends Model
{
    use BelongsToTenant, HasUuids;

    /** The human-readable reference shown to people, e.g. C-0042. */
    public function reference(): string
    {
        return 'C-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        // A deleted customer's contracts stay readable.
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return HasMany<Installment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('number');
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'installment_count' => 'integer',
            'principal' => 'decimal:4',
            'down_payment' => 'decimal:4',
            'financed' => 'decimal:4',
            'markup_value' => 'decimal:4',
            'markup_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'start_date' => 'date',
            'first_due_date' => 'date',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
