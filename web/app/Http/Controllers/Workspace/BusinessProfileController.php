<?php

namespace App\Http\Controllers\Workspace;

use App\Documents\BusinessProfile;
use App\Documents\DocumentPreferences;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\BusinessProfileRequest;
use App\Http\Requests\DocumentPreferencesRequest;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * One page for who the documents come from (names, phone, address, CR and VAT numbers, footer, logo, a signature drawn on
 * the screen) and how they look by default (Win Plan PP8). Everyone sees it; the owner and managers change it.
 */
final class BusinessProfileController
{
    public function edit(Request $request, CurrentTenant $current): View
    {
        $tenant = $this->tenant($current);
        $profile = BusinessProfile::for($tenant);

        return view('app.business', [
            'profile' => $profile,
            'logo' => $profile->logo()?->dataUri(),
            'signature' => $profile->signature()?->dataUri(),
            'preferences' => DocumentPreferences::for($tenant),
            'branded' => Entitlements::for($tenant)->check(Feature::CustomBranding)->enabled(),
            'canEdit' => $this->canEdit($request, $tenant),
        ]);
    }

    public function update(BusinessProfileRequest $request, CurrentTenant $current): RedirectResponse
    {
        BusinessProfile::for($this->tenant($current))->save($request->validated(), $request->user());

        return redirect()->route('app.settings.business')->with('status', __('Business profile saved.'));
    }

    /** A logo (a file), or a signature (a file, or a drawing sent from the page as a PNG). */
    public function storeAsset(Request $request, string $slot, CurrentTenant $current): RedirectResponse
    {
        $tenant = $this->tenant($current);
        abort_unless($this->canEdit($request, $tenant), 403);

        $drawing = (string) $request->input('drawing', '');
        if ($slot === 'signature' && $drawing !== '') {
            $prefix = 'data:image/png;base64,';
            $bytes = str_starts_with($drawing, $prefix) ? base64_decode(substr($drawing, strlen($prefix)), true) : false;
            $file = $bytes === false ? throw ValidationException::withMessages(['file' => __('Draw the signature again, then save it.')]) : $bytes;
        } else {
            $request->validate(['file' => ['required', 'file', 'max:'.(BusinessProfile::MAX_BYTES / 1024)]]);
            $file = $request->file('file');
        }

        BusinessProfile::for($tenant)->storeAsset($slot, $file, $request->user());

        return redirect()->route('app.settings.business')->with('status', $slot === 'logo' ? __('Logo saved.') : __('Signature saved.'));
    }

    public function destroyAsset(Request $request, string $slot, CurrentTenant $current): RedirectResponse
    {
        $tenant = $this->tenant($current);
        abort_unless($this->canEdit($request, $tenant), 403);
        BusinessProfile::for($tenant)->removeAsset($slot, $request->user());

        return redirect()->route('app.settings.business')->with('status', $slot === 'logo' ? __('Logo removed.') : __('Signature removed.'));
    }

    public function updatePreferences(DocumentPreferencesRequest $request, CurrentTenant $current): RedirectResponse
    {
        DocumentPreferences::save($this->tenant($current), $request->preferences(), $request->user());

        return redirect()->route('app.settings.business')->with('status', __('Document choices saved.'));
    }

    private function tenant(CurrentTenant $current): Tenant
    {
        return $current->get() ?? abort(404);
    }

    private function canEdit(Request $request, Tenant $tenant): bool
    {
        return $request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false;
    }
}
