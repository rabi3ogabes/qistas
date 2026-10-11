<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Money taken off a contract without being paid (Win Plan PP6): the early-settlement rebate. Like the ledger it sits
 * beside, never edited: voiding the payment that settled the contract writes the same amount negated.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property string $type early_rebate
 * @property string $amount signed: a rebate is positive, its reversal negative
 * @property string|null $reason
 * @property string|null $transaction_id the payment that settled the contract
 * @property string|null $reverses_adjustment_id
 * @property array{waived?: list<array{installment_id: string, amount: string}>}|null $details
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 */
class ContractAdjustment extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Contract adjustments are never edited; write the reversal instead.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'details' => 'array', 'created_at' => 'datetime'];
    }
}
