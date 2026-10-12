<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A link through which the customer signs on their own phone (Win Plan K5, decision D6). It works once, for a day, and
 * only after the six-digit code sent to them apart; five wrong codes close it. Only hashes of the link and the code are
 * kept. Found from its public page by App\Actions\Paperwork\SignThroughLink::find, outside any workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property string $token_hash
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $created_by_user_id
 */
class SignatureLink extends Model
{
    use BelongsToTenant, HasUuids;

    /** Wrong codes before the link closes. */
    public const ATTEMPTS = 5;

    /** How long a link works. */
    public const HOURS = 24;

    protected $hidden = ['token_hash', 'code_hash'];

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture() && $this->attempts < self::ATTEMPTS;
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }
}
