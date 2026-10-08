<?php

namespace App\Models;

use App\Notifications\ResetPasswordQueued;
use App\Notifications\VerifyEmailQueued;
use App\Tenancy\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

// platform_role and status are deliberately not fillable: no request payload can ever set them.
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    /** Platform staff who may open /admin (and must have two-factor authentication to do so). */
    public const ADMIN_ROLES = ['admin', 'super_admin'];

    protected $attributes = [
        'locale' => 'en',
        'status' => 'active',
    ];

    /** @return BelongsToMany<Tenant, $this> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')->withPivot('role')->withTimestamps();
    }

    /**
     * The workspace this person works in: the one they chose (only the admin test tools choose), if they still
     * belong to it and it is not suspended; otherwise their oldest membership that is not suspended. A test
     * workspace is only ever entered on purpose, never picked by default.
     */
    public function primaryTenant(): ?Tenant
    {
        $active = $this->tenants()->where('tenants.status', 'active');

        if ($this->current_tenant_id !== null) {
            $chosen = (clone $active)->where('tenants.id', $this->current_tenant_id)->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $active->where('tenants.is_test', false)
            ->orderBy('tenant_users.created_at')->orderBy('tenants.id')->first();
    }

    /** This person's role in one workspace, or null if they are not a member. */
    public function roleIn(string $tenantId): ?TenantRole
    {
        $role = DB::table('tenant_users')->where(['user_id' => $this->id, 'tenant_id' => $tenantId])->value('role');

        return is_string($role) ? TenantRole::tryFrom($role) : null;
    }

    public function isPlatformAdmin(): bool
    {
        return in_array($this->platform_role, self::ADMIN_ROLES, true);
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Suspended itself, or a member only of suspended workspaces. Platform staff belong to no workspace
     * and are only affected by their own status.
     */
    public function isSuspended(): bool
    {
        if ($this->status === 'suspended') {
            return true;
        }

        $workspaces = $this->tenants()->pluck('tenants.status');

        return $workspaces->isNotEmpty() && $workspaces->every(fn (string $status) => $status === 'suspended');
    }

    // Both emails are queued: a request's duration never reveals whether an account exists (password reset),
    // and a slow or failing mail server neither slows the page nor loses the message (the queue retries).
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordQueued($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailQueued);
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
