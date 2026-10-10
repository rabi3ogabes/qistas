<?php

namespace App\Actions\Workspace;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;

/**
 * Whether everyone who opens this business's books on a phone must unlock Qistas first (fingerprint, face or the
 * phone's PIN). The lock itself runs on each phone; this is the business's rule, and every change is recorded.
 * Callers check that $by may manage the workspace's settings.
 */
final class SetAppLockPolicy
{
    public function handle(Tenant $tenant, bool $required, User $by): bool
    {
        $before = (bool) $tenant->require_app_lock;

        if ($before !== $required) {
            $tenant->forceFill(['require_app_lock' => $required])->save();

            Audit::record('workspace.app_lock_policy_changed', $tenant, [
                'require_app_lock' => ['from' => $before, 'to' => $required],
            ], tenantId: $tenant->id, userId: $by->id);
        }

        return $required;
    }
}
