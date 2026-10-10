<?php

namespace App\Actions\Account;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\TenantRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** The owner changes their mind within the 30 days: the business is restored exactly as it was. */
final class CancelAccountDeletion
{
    /** @throws AuthorizationException when $by is not the owner */
    public function handle(User $by, Tenant $tenant): void
    {
        if ($by->roleIn($tenant->id) !== TenantRole::Owner) {
            throw new AuthorizationException(__('Only the owner can restore the business.'));
        }
        if (! $tenant->isBeingDeleted()) {
            return;
        }

        DB::transaction(function () use ($by, $tenant): void {
            $tenant->forceFill(['deletion_requested_at' => null, 'delete_after' => null])->save();

            DB::table('account_deletions')
                ->where('tenant_id', $tenant->id)->where('scope', 'workspace')
                ->whereNull('cancelled_at')->whereNull('completed_at')
                ->update(['cancelled_at' => now(), 'updated_at' => now()]);

            Audit::record('account.deletion_cancelled', $tenant, tenantId: $tenant->id, userId: $by->id);
        });
    }
}
