<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConfirmWorkspaceSetup;
use App\Http\Api\AccountPayload;
use App\Http\Requests\WorkspaceSetupRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/** The first-run card (spec 2026-11-01 §3.3): the owner names the business and confirms where it is, once. */
final class WorkspaceSetupController
{
    public function update(WorkspaceSetupRequest $request, CurrentTenant $current, ConfirmWorkspaceSetup $setup): JsonResponse
    {
        $tenant = $current->get() ?? abort(403);
        $user = $request->user() ?? abort(403);

        /** @var array{name: string, country?: string|null, timezone?: string|null} $data */
        $data = $request->validated();

        $tenant = $setup->handle($tenant, $user, $data);

        return response()->json(['data' => ['tenant' => AccountPayload::tenant($user, $tenant)]]);
    }
}
