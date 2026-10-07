<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as owned by one workspace.
 *
 * Reads are scoped by TenantScope. On create, tenant_id is always taken from the active context and any
 * value the caller supplied is overwritten. A row can never be moved to another workspace.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenantId = app(CurrentTenant::class)->id() ?? throw new NoTenantContext($model::class);
            $model->setAttribute('tenant_id', $tenantId);
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('A record cannot be moved to another workspace.');
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
