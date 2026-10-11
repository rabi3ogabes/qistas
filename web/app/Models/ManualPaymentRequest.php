<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A bank transfer (or wallet payment) an owner reports for a plan, with its receipt (Win Plan PP13). The Qistas team
 * approves it, which puts the workspace on the plan for the period paid, or rejects it with a reason. Written only by
 * App\Billing\ManualPayments; nothing here is mass-assignable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $period month | year
 * @property string $amount
 * @property string $currency
 * @property string $method bank_transfer | wallet
 * @property string $reference
 * @property string $status pending | approved | rejected
 * @property string|null $idempotency_key
 * @property string|null $created_by_user_id
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $note
 * @property Carbon $created_at
 */
class ManualPaymentRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const PERIODS = ['month', 'year'];

    public const METHODS = ['bank_transfer', 'wallet'];

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasOne<ManualPaymentReceipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(ManualPaymentReceipt::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
