<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;

/** The products list (Win Plan PP7): everyone in the business picks from it; those who write keep it up to date. */
final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->canWrite() ?? false;
    }

    public function update(User $user, Product $product): bool
    {
        return $this->create($user) && $product->tenant_id === app(CurrentTenant::class)->id();
    }

    private function role(User $user): ?TenantRole
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : $user->roleIn($tenantId);
    }
}
