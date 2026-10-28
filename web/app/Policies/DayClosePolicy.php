<?php

namespace App\Policies;

use App\Models\DayClose;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;

/**
 * The end-of-day close (daily-habit wave A, H4). The team reads the closes; anyone who records payments closes a day
 * (a viewer only reads); only owners and managers reopen one, with a reason (D10). A close is a signed record and is
 * never edited or deleted: there is deliberately no `update` or `delete` ability.
 */
final class DayClosePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->role($user) !== null;
    }

    public function view(User $user, DayClose $close): bool
    {
        return $this->role($user) !== null && $this->inActiveWorkspace($close);
    }

    /** Close a day. */
    public function create(User $user): bool
    {
        return $this->role($user)?->canWrite() ?? false;
    }

    public function reopen(User $user, DayClose $close): bool
    {
        return ($this->role($user)?->canDelete() ?? false) && $this->inActiveWorkspace($close);
    }

    private function role(User $user): ?TenantRole
    {
        $tenantId = app(CurrentTenant::class)->id();

        return $tenantId === null ? null : $user->roleIn($tenantId);
    }

    private function inActiveWorkspace(DayClose $close): bool
    {
        return $close->tenant_id === app(CurrentTenant::class)->id();
    }
}
