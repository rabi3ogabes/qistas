<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One payment from a customer spread over several of their contracts (Win Plan PP6): a ledger line on each contract,
 * tied together here. The idempotency key makes a retry return this batch instead of paying twice.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string $amount
 * @property string $method
 * @property string|null $idempotency_key
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 */
class PaymentBatch extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'batch_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'created_at' => 'datetime'];
    }
}
