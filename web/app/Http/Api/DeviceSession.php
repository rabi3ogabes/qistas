<?php

namespace App\Http\Api;

use App\Http\ApiException;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What a successful sign-in answers with: a token for this device, and the account it opens. */
final class DeviceSession
{
    public static function issue(User $user, Request $request, int $status, ?CarbonInterface $expires = null): JsonResponse
    {
        $tenant = $user->primaryTenant() ?? throw new ApiException('no_workspace', __('This account does not belong to a workspace.'), 403);

        $token = $user->createToken(
            (string) ($request->input('device_name') ?: __('Mobile app')),
            ['app'],
            $expires ?? now()->addDays((int) config('qistas.security.api_token_days')),
        );

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ...AccountPayload::for($user, $tenant),
        ]], $status);
    }
}
