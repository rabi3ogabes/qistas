<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// owner_user_id and status are set by trusted code only.
#[Fillable(['name', 'slug', 'country', 'currency'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected $attributes = [
        'status' => 'active',
        'is_test' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_test' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')->withPivot('role')->withTimestamps();
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return HasMany<TenantOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(TenantOverride::class);
    }

    /**
     * The plan in force right now: the subscribed plan while the subscription is current, otherwise the
     * default (Free) plan. Always read fresh, so a billing or admin change applies on the very next call.
     */
    public function currentPlan(): Plan
    {
        $subscription = $this->subscription()->with('plan')->first();

        return $subscription?->isCurrent() ? $subscription->plan : Plan::default();
    }

    /** Put the workspace on a plan, changing its one subscription in place. */
    public function subscribeTo(Plan $plan, string $status = 'active', ?CarbonInterface $periodEnd = null): Subscription
    {
        $subscription = $this->subscription()->firstOrNew();
        $subscription->forceFill(['plan_id' => $plan->id, 'status' => $status, 'current_period_end' => $periodEnd])->save();
        $subscription->setRelation('plan', $plan);
        $this->setRelation('subscription', $subscription);

        return $subscription;
    }
}
