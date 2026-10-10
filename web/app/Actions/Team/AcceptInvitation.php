<?php

namespace App\Actions\Team;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\ApiException;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Someone opens an invitation link and joins the business with the role it carries. A link works once; afterwards (or
 * once expired or revoked) it answers 410 `invitation_invalid`. The business they joined becomes the one they see.
 */
final class AcceptInvitation
{
    /** The open invitation behind a link's secret, or null. Read outside any workspace: the visitor is not in it yet. */
    public static function find(string $token): ?TenantInvitation
    {
        $invitation = TenantInvitation::withoutGlobalScope(TenantScope::class)->with('tenant')
            ->where('token_hash', hash('sha256', $token))->first();

        return $invitation !== null && $invitation->isOpen() && $invitation->tenant?->status === 'active' ? $invitation : null;
    }

    /** @throws ApiException invitation_invalid (410) */
    public function handle(string $token, User $user): Tenant
    {
        return DB::transaction(function () use ($token, $user): Tenant {
            $invitation = self::find($token);
            if ($invitation === null) {
                throw new ApiException('invitation_invalid', __('This invitation link is no longer valid. Ask for a new one.'), 410);
            }
            $tenant = $invitation->tenant;
            Entitlements::for($tenant)->assertEnabled(Feature::Members);

            // Mark it used first, under a lock, so the same link cannot be accepted twice at once.
            $claimed = DB::table('tenant_invitations')->where('id', $invitation->id)->whereNull('accepted_at')
                ->update(['accepted_at' => now(), 'accepted_by_user_id' => $user->id, 'updated_at' => now()]);
            if ($claimed === 0) {
                throw new ApiException('invitation_invalid', __('This invitation link is no longer valid. Ask for a new one.'), 410);
            }

            if ($user->roleIn($tenant->id) === null) {
                $tenant->users()->attach($user->id, ['role' => $invitation->role->value]);
            }
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();

            Audit::record('team.invitation_accepted', $invitation, ['role' => $invitation->role->value], tenantId: $tenant->id, userId: $user->id);

            return $tenant;
        });
    }
}
