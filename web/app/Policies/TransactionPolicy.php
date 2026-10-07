<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;

/**
 * The team reads the ledger and records payments; only owners and managers void one. Nobody edits or deletes
 * a transaction: there is deliberately no `update` or `delete` ability.
 */
final class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $this->role($user) !== null && $this->inActiveWorkspace($transaction);
    }

    /** Record a payment. */
    public function create(User $user): bool
    {
        return $this->role($user)?->canWrite() ?? false;
    }

    public function void(User $user, Transaction $transaction): bool
    {
        return ($this->role($user)?->canDelete() ?? false) && $this->inActiveWorkspace($transaction);
    }

    private function role(User $user): ?TenantRole
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : $user->roleIn($tenantId);
    }

    private function inActiveWorkspace(Transaction $transaction): bool
    {
        return $transaction->tenant_id === app(CurrentTenant::class)->id();
    }
}
