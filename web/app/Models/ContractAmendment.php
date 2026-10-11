<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A change to a contract's plan (Win Plan PP6): the instalments before and after, why, and who confirmed it. Kept for
 * the partner and the accountant; never edited.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property string $type reschedule | skip | move
 * @property string $reason
 * @property list<array{number: int, due_date: string, amount: string}> $old_schedule
 * @property list<array{number: int, due_date: string, amount: string}> $new_schedule
 * @property string|null $created_by_user_id
 * @property string|null $approved_by_user_id
 * @property Carbon $created_at
 */
class ContractAmendment extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const TYPES = ['reschedule', 'skip', 'move'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Plan changes are history; they are never edited.'));
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    protected function casts(): array
    {
        return ['old_schedule' => 'array', 'new_schedule' => 'array', 'created_at' => 'datetime'];
    }
}
