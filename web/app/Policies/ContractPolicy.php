<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;

/**
 * Contracts are opened and read by the team, cancelled by owners and managers, and never edited or deleted:
 * the schedule is what the customer agreed to. There is deliberately no `update` or `delete` ability.
 */
final class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, Contract $contract): bool
    {
        return $this->role($user) !== null && $this->inActiveWorkspace($contract);
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->canWrite() ?? false;
    }

    public function cancel(User $user, Contract $contract): bool
    {
        return ($this->role($user)?->canDelete() ?? false) && $this->inActiveWorkspace($contract);
    }

    private function role(User $user): ?TenantRole
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : $user->roleIn($tenantId);
    }

    private function inActiveWorkspace(Contract $contract): bool
    {
        return $contract->tenant_id === app(CurrentTenant::class)->id();
    }
}
