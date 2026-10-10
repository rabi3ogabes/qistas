<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The record of a scheduled or cash contract becoming open (Win Plan PP4): how many instalments it superseded and the
 * opening line that carried the rest over. Written once per contract by App\Actions\ConvertToOpen, never changed.
 *
 * @property string $contract_id
 * @property string $from_type
 * @property int $superseded_count
 * @property string $opening_amount
 * @property string|null $transaction_id
 * @property Carbon $created_at
 */
class ContractConversion extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['superseded_count' => 'integer', 'opening_amount' => 'decimal:4'];
    }
}
