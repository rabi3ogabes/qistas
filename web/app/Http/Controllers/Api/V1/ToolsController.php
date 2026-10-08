<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Tenant;
use App\Settings\SettingsRegistry;
use App\Settings\TenantSettings;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The instalment tools a workspace may configure: the settings of every feature that is on for it. Anyone in the
 * workspace may look; the owner and managers may change them. A feature that is not on is neither listed nor
 * changeable (403 feature_unavailable, or 402 when only the plan lacks it).
 */
final class ToolsController
{
    public function index(Request $request, CurrentTenant $current, SettingsRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($current);
        $definitions = $registry->definitionsFor($tenant);
        $values = TenantSettings::for($tenant)->values($definitions);

        return response()->json([
            'data' => array_map(fn ($definition) => $definition->toArray($values[$definition->key]), $definitions),
            'meta' => ['can_edit' => $this->canEdit($request, $tenant)],
        ]);
    }

    public function update(Request $request, string $key, CurrentTenant $current, SettingsRegistry $registry): JsonResponse
    {
        $tenant = $this->tenant($current);
        abort_unless($this->canEdit($request, $tenant), 403);
        $definition = $registry->find($key) ?? abort(404);

        $settings = TenantSettings::for($tenant);
        $settings->set($key, $request->input('value'), $request->user());

        return response()->json(['data' => $definition->toArray($settings->get($key))]);
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
