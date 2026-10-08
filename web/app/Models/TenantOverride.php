<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One workspace's own setting for one feature (a grant, or a denial), with a reason and optionally an end date.
 * created_by_user_id is set by the admin action, never from input.
 *
 * @property Carbon|null $expires_at
 */
#[Fillable(['feature_key', 'enabled', 'limit_value', 'reason', 'expires_at'])]
class TenantOverride extends Model
{
    use HasUuids;

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @param  Builder<TenantOverride>  $query
     * @return Builder<TenantOverride>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'limit_value' => 'integer', 'expires_at' => 'datetime'];
    }
}
