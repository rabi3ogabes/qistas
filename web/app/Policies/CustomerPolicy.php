<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;

/**
 * Who may do what with customers, by role in the ACTIVE workspace. A record from any other workspace is
 * refused even if it somehow reached this point (the tenant scope should already have hidden it).
 */
final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->role($user) !== null && $this->inActiveWorkspace($customer);
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->canWrite() ?? false;
    }

    public function update(User $user, Customer $customer): bool
    {
        return ($this->role($user)?->canWrite() ?? false) && $this->inActiveWorkspace($customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return ($this->role($user)?->canDelete() ?? false) && $this->inActiveWorkspace($customer);
    }

    private function role(User $user): ?TenantRole
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : $user->roleIn($tenantId);
    }

    private function inActiveWorkspace(Customer $customer): bool
    {
        return $customer->tenant_id === app(CurrentTenant::class)->id();
    }
}
