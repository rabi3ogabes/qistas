<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Account\CancelAccountDeletion;
use App\Actions\Account\RequestAccountDeletion;
use App\Http\ApiException;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** "Delete my account" on the website: what will happen, the confirmation, and the owner's way back. */
final class AccountDeletionController
{
    public function show(Request $request, CurrentTenant $current): View
    {
        $tenant = $current->get() ?? abort(403);

        return view('app.account-delete', [
            'tenant' => $tenant,
            'isOwner' => $request->user()->roleIn($tenant->id) === TenantRole::Owner,
            'twoFactor' => $request->user()->hasConfirmedTwoFactor(),
            'days' => RequestAccountDeletion::DAYS_TO_RESTORE,
        ]);
    }

    public function store(Request $request, CurrentTenant $current, RequestAccountDeletion $delete): RedirectResponse
    {
        $tenant = $current->get() ?? abort(403);

        try {
            $result = $delete->handle($request->user(), $tenant, $request->only(['password', 'code', 'recovery_code', 'confirm_name']));
        } catch (ApiException $e) {
            // The second step is missing or wrong: show it under the code field.
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        if ($result['scope'] === 'login') {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home')->with('status', __('Your login was deleted.'));
        }

        return redirect()->route('app.dashboard')->with('status', __('Your business will be deleted on :date. You can restore it until then.', ['date' => $result['restore_until']]));
    }

    public function destroy(Request $request, CurrentTenant $current, CancelAccountDeletion $cancel): RedirectResponse
    {
        $cancel->handle($request->user(), $current->get() ?? abort(403));

        return redirect()->route('app.dashboard')->with('status', __('Your business is restored.'));
    }
}
