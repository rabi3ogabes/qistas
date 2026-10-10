<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group of customers ("Shop 2", "Government staff") that filters every list (Win Plan PP12). Its name is unique in the
 * business whatever its case.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $colour one of COLOURS
 */
#[Fillable(['name', 'colour'])]
class Tag extends Model
{
    use BelongsToTenant, HasUuids;

    public const COLOURS = ['grey', 'gold', 'green', 'blue', 'red', 'purple'];

    /** @return BelongsToMany<Customer, $this> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_tags');
    }

    /** @return array{id: string, name: string, colour: string} */
    public function toSummary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'colour' => $this->colour];
    }
}
