<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Workspace\SetAppLockPolicy;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The business's security rules for its phones, from the settings page. Owner and managers only. */
final class SecurityPolicyController
{
    public function update(Request $request, CurrentTenant $current, SetAppLockPolicy $policy): RedirectResponse
    {
        $tenant = $current->get() ?? abort(403);
        abort_unless($request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false, 403);

        $policy->handle($tenant, $request->boolean('require_app_lock'), $request->user());

        return redirect()->route('app.settings.tools')->with('status', __('Saved.'));
    }
}
