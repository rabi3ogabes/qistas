<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * How much of a transaction went to one instalment (negative for a reversal). Immutable, like the ledger.
 *
 * @property string $installment_id
 * @property string $amount
 */
class TransactionAllocation extends Model
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

    /** @return BelongsTo<Installment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
