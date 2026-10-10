<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phones and browsers signed in to my account (Win Plan PP16): each sign-in is its own token, named after the
 * device, with when and from where it was last used. Any one can be signed out. API access tokens made for other
 * software are not sign-ins and live in TokenController.
 */
final class DeviceController
{
    public function index(Request $request): JsonResponse
    {
        // Always a device's own token here: the route asks for the "app" ability.
        $currentId = $request->user()->currentAccessToken()->getKey();

        return response()->json(['data' => $this->signIns($request)->orderByDesc('last_used_at')->orderByDesc('created_at')->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'current' => $token->id === $currentId,
                'last_used_at' => $token->last_used_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'last_used_ip' => $token->getAttribute('last_used_ip'),
                'signed_in_at' => $token->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => $token->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all()]);
    }

    public function destroy(Request $request, string $device): Response
    {
        $this->signIns($request)->whereKey($device)->firstOrFail()->delete();

        return response()->noContent();
    }

    /** @return MorphMany<PersonalAccessToken, User> */
    private function signIns(Request $request): MorphMany
    {
        return $request->user()->tokens()->where('abilities', 'like', '%"app"%');
    }
}
