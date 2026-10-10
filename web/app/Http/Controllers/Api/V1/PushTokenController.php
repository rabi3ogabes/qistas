<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PushToken;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The phones that receive pushes (Win Plan PP9). The app registers its Firebase token at sign-in and whenever Firebase
 * gives it a new one; the same token again only refreshes it, and a token another person registered moves to whoever
 * signed in on the phone last. Signing out removes it.
 */
final class PushTokenController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(PushToken::PLATFORMS)],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);

        // A token is one install of the app: it can only ever belong to one person, in one workspace.
        PushToken::releaseElsewhere($data['token'], (string) $this->current->id());

        $token = PushToken::query()->updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()?->id,
            'platform' => $data['platform'],
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ]);

        return response()->json(['data' => ['id' => $token->id, 'platform' => $token->platform]], $token->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $token): Response
    {
        $row = PushToken::query()->where('token', $token)->where('user_id', $request->user()?->id)->first() ?? abort(404);
        $row->forceFill(['revoked_at' => now()])->save();

        return response()->noContent();
    }
}
