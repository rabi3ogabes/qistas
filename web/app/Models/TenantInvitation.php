<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation into a business, opened by a link. Written only by App\Actions\Team\*: the role, token and dates are
 * set by trusted code, never filled from a request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property TenantRole $role
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['name', 'email', 'phone'])]
class TenantInvitation extends Model
{
    use BelongsToTenant, HasUuids;

    /** How long a link works. */
    public const DAYS = 7;

    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Not used, not revoked, not expired.
     *
     * @param  Builder<TenantInvitation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return array<string, mixed> what the API and the screens show; never the token or its fingerprint */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'invited_by' => $this->invitedBy?->name,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
