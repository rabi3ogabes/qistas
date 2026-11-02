<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\ConfirmWorkspaceSetup;
use App\Http\Requests\WorkspaceSetupRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;

/**
 * The first-run card on the dashboard (spec 2026-11-01 §3.3): the owner names the business and confirms where it is.
 * The rules, the owner-only check and the action are the phone app's (PUT /api/v1/workspace/setup); only the answer is
 * the web's: back to the dashboard with a message, or back to the card with the mistake next to its field.
 */
final class SetupController
{
    public function update(WorkspaceSetupRequest $request, CurrentTenant $current, ConfirmWorkspaceSetup $setup): RedirectResponse
    {
        $tenant = $current->get() ?? abort(403);
        $user = $request->user() ?? abort(403);

        /** @var array{name: string, country?: string|null, timezone?: string|null} $data */
        $data = $request->validated();

        $setup->handle($tenant, $user, $data);

        return redirect()->route('app.dashboard')->with('status', __('Saved. Receipts and statements will carry this name.'));
    }
}
