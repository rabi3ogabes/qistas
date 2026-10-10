<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a contract sold (Win Plan PP7): its name, how many, its serial or IMEI, what it cost and its price. Written
 * with the contract by App\Actions\CreateContract and never changed: it is part of what the customer agreed to.
 *
 * @property string $contract_id
 * @property string|null $product_id
 * @property int $position
 * @property string $name
 * @property int $quantity
 * @property string|null $serial
 * @property string|null $cost
 * @property string|null $price
 */
class ContractItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['position' => 'integer', 'quantity' => 'integer', 'cost' => 'decimal:4', 'price' => 'decimal:4'];
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
