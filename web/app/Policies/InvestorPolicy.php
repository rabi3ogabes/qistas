<?php

namespace App\Policies;

use App\Models\Investor;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;

/**
 * Who funds the business is for the people who run its money (Win Plan PP3): collectors never see investors; owners,
 * managers, accountants and viewers do; accountants and above add investors and record deposits and withdrawals; only
 * owners and managers reverse an entry, as only they void a payment. Nobody edits or deletes an entry.
 */
final class InvestorPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role($user)?->seesInvestors() ?? false;
    }

    public function view(User $user, Investor $investor): bool
    {
        return $this->viewAny($user) && $this->inActiveWorkspace($investor);
    }

    public function create(User $user): bool
    {
        return $this->role($user)?->managesInvestors() ?? false;
    }

    /** Rename, change the commission, archive; and record a deposit or a withdrawal. */
    public function update(User $user, Investor $investor): bool
    {
        return $this->create($user) && $this->inActiveWorkspace($investor);
    }

    public function reverse(User $user, Investor $investor): bool
    {
        return ($this->role($user)?->canDelete() ?? false) && $this->viewAny($user) && $this->inActiveWorkspace($investor);
    }

    private function role(User $user): ?TenantRole
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : $user->roleIn($tenantId);
    }

    private function inActiveWorkspace(Investor $investor): bool
    {
        return $investor->tenant_id === app(CurrentTenant::class)->id();
    }
}
