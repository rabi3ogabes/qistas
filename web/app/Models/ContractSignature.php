<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time the customer signed a contract (Win Plan K5, CT23): on the owner's screen or through a link on their own
 * phone. The latest is the contract's signature; earlier ones stay as history. Written only by App\Actions\Paperwork.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property string $name the customer's name when they signed
 * @property string $image base64 PNG
 * @property string $method screen | link
 * @property string|null $signature_link_id
 * @property string|null $recorded_by_user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $signed_at
 */
class ContractSignature extends Model
{
    use BelongsToTenant, HasUuids;

    protected $hidden = ['image', 'ip', 'user_agent'];

    /** The picture, ready for an <img> or a PDF. */
    public function dataUri(): string
    {
        return 'data:image/png;base64,'.$this->image;
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
