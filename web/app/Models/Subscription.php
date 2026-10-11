<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace's plan. Written only by trusted code (sign-up, billing, admin) through forceFill, never from
 * request data: nothing here is mass-assignable.
 *
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $status active | trialing | past_due | on_hold | paused | canceled | expired
 * @property Carbon|null $current_period_end
 * @property string|null $source play | appstore | web | manual | admin; null for sign-up and sandbox plans
 * @property string|null $store_product_id
 * @property string|null $purchase_token_hash
 * @property bool|null $auto_renew
 * @property string|null $store_status
 */
class Subscription extends Model
{
    use HasUuids;

    /** Statuses that keep the paid plan in force. */
    public const IN_FORCE = ['active', 'trialing', 'past_due'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether the plan is still granted. A missed renewal keeps the plan for the grace period, so a late
     * payment webhook never cuts someone off; after it, the workspace is Free again (data is untouched).
     */
    public function isCurrent(): bool
    {
        if (! in_array($this->status, self::IN_FORCE, true)) {
            return false;
        }

        return $this->current_period_end === null
            || $this->current_period_end->copy()->addDays((int) config('qistas.billing.grace_days'))->isFuture();
    }

    protected function casts(): array
    {
        return ['current_period_end' => 'datetime', 'auto_renew' => 'boolean'];
    }
}
