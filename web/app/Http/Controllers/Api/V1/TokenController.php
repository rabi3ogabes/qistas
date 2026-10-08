<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Resources\TokenResource;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API access tokens: made on purpose, to connect another program (an accounting tool, a spreadsheet) to this
 * workspace. How many a plan allows is the `api_tokens` feature. A person's own sign-in on a phone is a different
 * kind of token and is not counted or listed here. A token made here cannot make or revoke tokens.
 */
final class TokenController
{
    public const ABILITY = 'integration';

    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($this->own($request)->orderBy('created_at')->get());
    }

    public function store(Request $request, CurrentTenant $current): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:100']]);

        Entitlements::for($current->get())->assertCanCreate(Feature::ApiTokens);

        $token = $request->user()->createToken(trim((string) $request->input('name')), [self::ABILITY], now()->addDays(365));

        return response()->json(['data' => [
            ...(new TokenResource($token->accessToken))->resolve(),
            // The only time the secret is shown: it is stored only as a hash.
            'token' => $token->plainTextToken,
        ]], 201);
    }

    public function destroy(Request $request, string $token): Response
    {
        $this->own($request)->whereKey($token)->firstOrFail()->delete();

        return response()->noContent();
    }

    /** @return MorphMany<PersonalAccessToken, User> */
    private function own(Request $request)
    {
        return $request->user()->tokens()->where('abilities', 'like', '%"'.self::ABILITY.'"%');
    }
}
