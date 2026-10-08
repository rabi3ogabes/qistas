<?php

namespace App\Http\Controllers\Workspace;

use App\Models\Tenant;
use App\Settings\SettingsRegistry;
use App\Settings\TenantSettings;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** "Instalment tools": the settings of the features that are on for this workspace, one small form each. */
final class ToolsController
{
    public function show(Request $request, CurrentTenant $current, SettingsRegistry $registry): View
    {
        $tenant = $this->tenant($current);
        $definitions = $registry->definitionsFor($tenant);
        $values = TenantSettings::for($tenant)->values($definitions);

        return view('app.tools', [
            'tools' => array_map(fn ($definition) => $definition->toArray($values[$definition->key]), $definitions),
            'canEdit' => $this->canEdit($request, $tenant),
        ]);
    }

    public function update(Request $request, string $key, CurrentTenant $current, SettingsRegistry $registry): RedirectResponse
    {
        $tenant = $this->tenant($current);
        abort_unless($this->canEdit($request, $tenant), 403);
        abort_if($registry->find($key) === null, 404);

        try {
            TenantSettings::for($tenant)->set($key, $request->input('value'), $request->user());
        } catch (ValidationException $e) {
            // Each tool is its own form, so each has its own error bag and a mistake shows under the right one.
            return back()->withErrors($e->errors(), 'tool-'.$key)->withInput();
        }

        return redirect()->route('app.settings.tools')->with('status', __('Saved.'));
    }

    private function tenant(CurrentTenant $current): Tenant
    {
        return $current->get() ?? abort(403);
    }

    private function canEdit(Request $request, Tenant $tenant): bool
    {
        return $request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false;
    }
}
