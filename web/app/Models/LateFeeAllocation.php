<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * How much of a payment went to one late fee (negative for its void). Immutable, like the ledger.
 *
 * @property string $transaction_id
 * @property string $late_fee_id
 * @property string $amount
 */
class LateFeeAllocation extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Allocations are immutable.'));
        static::deleting(fn () => throw new LogicException('Allocations cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    /** @return BelongsTo<LateFee, $this> */
    public function lateFee(): BelongsTo
    {
        return $this->belongsTo(LateFee::class);
    }
}
