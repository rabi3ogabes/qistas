<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AppNotification;
use App\Notifications\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Each person's own alerts (Win Plan PP9): their inbox, newest first, and their choices of what to be told and when.
 * Nobody sees anyone else's.
 */
final class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $userId = (string) $request->user()?->id;
        $page = AppNotification::query()->where('user_id', $userId)->latest('created_at')->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'data' => $n->data ?? (object) [],
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => $this->unread($userId),
            ],
        ]);
    }

    /** Marks the given entries read, or all of them when none are named. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['uuid']]);
        $userId = (string) $request->user()?->id;

        AppNotification::query()->where('user_id', $userId)->whereNull('read_at')
            ->when(isset($data['ids']), fn ($query) => $query->whereKey($data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => $this->unread($userId)]]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => Preferences::for((string) $request->user()?->id)]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        // Only the kinds and fields the rules name come back from validation; anything else sent is ignored.
        $data = $request->validate(Preferences::rules());

        return response()->json(['data' => Preferences::update((string) $request->user()?->id, $data)]);
    }

    private function unread(string $userId): int
    {
        return AppNotification::query()->where('user_id', $userId)->whereNull('read_at')->count();
    }
}
