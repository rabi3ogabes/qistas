<?php

namespace App\Http\Controllers\Api\V1;

use App\Documents\BusinessProfile;
use App\Documents\DocumentPreferences;
use App\Http\Requests\BusinessProfileRequest;
use App\Http\Requests\DocumentPreferencesRequest;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who the documents come from (the business profile, with its logo and signature) and how they look by default (Win Plan
 * PP8). Anyone in the workspace may look; the owner and managers change them.
 */
final class BusinessProfileController
{
    public function show(Request $request, CurrentTenant $current): JsonResponse
    {
        $tenant = $this->tenant($current);

        return response()->json(['data' => BusinessProfile::for($tenant)->toArray(), 'meta' => ['can_edit' => $this->canEdit($request, $tenant)]]);
    }

    public function update(BusinessProfileRequest $request, CurrentTenant $current): JsonResponse
    {
        $tenant = $this->tenant($current);

        return response()->json(['data' => BusinessProfile::for($tenant)->save($request->validated(), $request->user())->toArray()]);
    }

    /** A new logo or signature (multipart `file`; a signature is a PNG). */
    public function storeAsset(Request $request, string $slot, CurrentTenant $current): JsonResponse
    {
        $tenant = $this->tenant($current);
        abort_unless($this->canEdit($request, $tenant), 403);
        $request->validate(['file' => ['required', 'file', 'max:'.(BusinessProfile::MAX_BYTES / 1024)]]);

        $profile = BusinessProfile::for($tenant);
        $profile->storeAsset($slot, $request->file('file'), $request->user());

        return response()->json(['data' => $profile->toArray()]);
    }

    public function destroyAsset(Request $request, string $slot, CurrentTenant $current): JsonResponse
    {
        $tenant = $this->tenant($current);
        abort_unless($this->canEdit($request, $tenant), 403);

        $profile = BusinessProfile::for($tenant);
        $profile->removeAsset($slot, $request->user());

        return response()->json(['data' => $profile->toArray()]);
    }

    public function preferences(CurrentTenant $current): JsonResponse
    {
        return response()->json(['data' => DocumentPreferences::for($this->tenant($current))->toArray()]);
    }

    public function updatePreferences(DocumentPreferencesRequest $request, CurrentTenant $current): JsonResponse
    {
        return response()->json(['data' => DocumentPreferences::save($this->tenant($current), $request->preferences(), $request->user())->toArray()]);
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
