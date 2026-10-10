<?php

namespace App\Actions\Team;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\TenantRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Changing a member's role, removing a member, and withdrawing an invitation. Every change is recorded. */
final class ManageMembers
{
    public function changeRole(Tenant $tenant, User $by, User $member, string $role): TenantRole
    {
        $byRole = $by->roleIn($tenant->id);
        $current = $member->roleIn($tenant->id) ?? throw new NotFoundHttpException;
        if (! TeamRules::canTouch($byRole, $current)) {
            TeamRules::deny();
        }

        $given = TenantRole::tryFrom($role);
        if ($given === null || $given === TenantRole::Owner) {
            throw ValidationException::withMessages(['role' => __('Choose a manager, accountant, collector or viewer.')]);
        }
        if (! in_array($given, TeamRules::assignable($byRole), true)) {
            TeamRules::deny();
        }

        if ($given !== $current) {
            $tenant->users()->updateExistingPivot($member->id, ['role' => $given->value]);
            Audit::record('team.role_changed', $member, ['role' => ['from' => $current->value, 'to' => $given->value]], tenantId: $tenant->id, userId: $by->id);
        }

        return $given;
    }

    /**
     * Takes someone out of the business at once. If it was their only business they are signed out everywhere (their
     * login itself stays, so they can be invited back); otherwise they carry on in their other business.
     */
    public function remove(Tenant $tenant, User $by, User $member): void
    {
        $current = $member->roleIn($tenant->id) ?? throw new NotFoundHttpException;
        if (! TeamRules::canTouch($by->roleIn($tenant->id), $current)) {
            TeamRules::deny();
        }

        DB::transaction(function () use ($tenant, $by, $member, $current): void {
            $tenant->users()->detach($member->id);

            if (! $member->tenants()->exists()) {
                DB::table('sessions')->where('user_id', $member->id)->delete();
                $member->tokens()->delete();
            }
            if ($member->current_tenant_id === $tenant->id) {
                $member->forceFill(['current_tenant_id' => null])->save();
            }

            Audit::record('team.member_removed', $member, ['role' => $current->value], tenantId: $tenant->id, userId: $by->id);
        });
    }

    public function revoke(Tenant $tenant, User $by, TenantInvitation $invitation): void
    {
        if (! TeamRules::canManage($by->roleIn($tenant->id))) {
            TeamRules::deny();
        }
        if ($invitation->revoked_at !== null || $invitation->accepted_at !== null) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record('team.invitation_revoked', $invitation, ['role' => $invitation->role->value], tenantId: $tenant->id, userId: $by->id);
    }
}
