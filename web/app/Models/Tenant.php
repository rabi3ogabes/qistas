<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// owner_user_id and status are set by trusted code only.
#[Fillable(['name', 'slug', 'country', 'currency'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'active',
    ];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')->withPivot('role')->withTimestamps();
    }
}
