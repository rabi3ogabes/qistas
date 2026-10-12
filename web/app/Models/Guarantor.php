<?php

namespace App\Models;

use App\Support\NationalId;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone who stands behind a contract (Win Plan K5, CT21): at most three on a contract. The ID number is encrypted at
 * rest and only ever shown masked, and never to a collector or a viewer. Written only by App\Actions\Paperwork.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property int $position
 * @property string $name
 * @property string $phone
 * @property string|null $relation how they know the customer, in the owner's own words
 * @property string|null $national_id
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['name', 'phone', 'relation', 'national_id'])]
#[Hidden(['national_id'])]
class Guarantor extends Model
{
    use BelongsToTenant, HasUuids;

    /** A contract has at most this many. */
    public const MAX_PER_CONTRACT = 3;

    /** The ID number with all but its last three digits hidden; never the full number. */
    public function maskedNationalId(): ?string
    {
        return NationalId::mask($this->national_id);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected function casts(): array
    {
        return ['national_id' => 'encrypted', 'position' => 'integer'];
    }
}
