<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something the shop sells (Win Plan PP7), to pick from when opening a contract: its name, a default price and what it
 * costs. No stock is counted. Archived products stay on the contracts that sold them and leave the picker.
 *
 * @property string $id
 * @property string $name
 * @property string|null $sku
 * @property string|null $default_price
 * @property string|null $cost
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'sku', 'default_price', 'cost'])]
class Product extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['default_price' => 'decimal:4', 'cost' => 'decimal:4', 'archived_at' => 'datetime'];
    }

    /** @param  Builder<Product>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
