<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\CancelAccountDeletion;
use App\Actions\Account\RequestAccountDeletion;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * "Delete my account" from the app. The owner's request deletes the business after 30 days (202, with the last day to
 * restore); anyone else's deletes their own login at once (200). The owner restores with DELETE.
 */
final class AccountDeletionController
{
    public function store(Request $request, CurrentTenant $current, RequestAccountDeletion $delete): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:50'],
            'confirm_name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $delete->handle($request->user(), $current->get() ?? abort(403), $request->only(['password', 'code', 'recovery_code', 'confirm_name']));

        return response()->json(['data' => $result], $result['scope'] === 'workspace' ? 202 : 200);
    }

    public function destroy(Request $request, CurrentTenant $current, CancelAccountDeletion $cancel): JsonResponse
    {
        try {
            $cancel->handle($request->user(), $current->get() ?? abort(403));
        } catch (AuthorizationException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return response()->json(['data' => ['restored' => true]]);
    }
}
