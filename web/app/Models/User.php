<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// platform_role and status are deliberately not fillable: no request payload can ever set them.
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $attributes = [
        'locale' => 'en',
        'status' => 'active',
    ];

    /** @return BelongsToMany<Tenant, $this> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')->withPivot('role')->withTimestamps();
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
            'password' => 'hashed',
        ];
    }
}
