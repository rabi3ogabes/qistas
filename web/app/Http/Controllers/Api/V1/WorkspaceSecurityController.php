<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workspace\SetAppLockPolicy;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The business's security rules for its phones. The owner and managers change them; everyone reads them in /me. */
final class WorkspaceSecurityController
{
    public function update(Request $request, CurrentTenant $current, SetAppLockPolicy $policy): JsonResponse
    {
        $tenant = $current->get() ?? abort(403);
        abort_unless($request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false, 403);

        $data = $request->validate(['require_app_lock' => ['required', 'boolean']]);

        return response()->json(['data' => [
            'require_app_lock' => $policy->handle($tenant, (bool) $data['require_app_lock'], $request->user()),
        ]]);
    }
}
