<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AppNotification;
use App\Notifications\Catalog;
use App\Notifications\Channels;
use App\Notifications\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Each person's own alerts (Win Plan PP9): their inbox, newest first, and their choices of what to be told, when and how
 * (in the app, by e-mail, by text message; Sprint 3 Task 1b). Nobody sees anyone else's.
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

    public function preferences(Request $request, Channels $channels): JsonResponse
    {
        return response()->json(['data' => Preferences::for((string) $request->user()?->id), 'meta' => $this->meta($request, $channels)]);
    }

    public function updatePreferences(Request $request, Channels $channels): JsonResponse
    {
        // Only the kinds and fields the rules name come back from validation; anything else sent is ignored.
        $data = $request->validate(Preferences::rules());

        return response()->json(['data' => Preferences::update((string) $request->user()?->id, $data), 'meta' => $this->meta($request, $channels)]);
    }

    /**
     * What the person can choose among (Sprint 3 Task 1b): each kind with its name and group in their language, each
     * channel with whether it is set up (e-mail also needs their verified address), and what the admin allows.
     *
     * @return array<string, mixed>
     */
    private function meta(Request $request, Channels $channels): array
    {
        $available = $channels->available();
        $available['email'] = $available['email'] && (bool) $request->user()?->hasVerifiedEmail();

        return [
            'kinds' => array_map(fn (string $kind) => [
                'key' => $kind, 'group' => Catalog::KINDS[$kind]['group'], 'label' => Catalog::label($kind), 'description' => Catalog::description($kind),
            ], array_keys(Catalog::KINDS)),
            'channels' => array_map(fn (string $channel) => ['available' => $available[$channel], 'label' => Catalog::channel($channel)], array_combine(Catalog::CHANNELS, Catalog::CHANNELS)),
            'allowed' => $channels->allowed(),
        ];
    }

    private function unread(string $userId): int
    {
        return AppNotification::query()->where('user_id', $userId)->whereNull('read_at')->count();
    }
}
