<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ReleaseNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What's new" (Win Plan PP17): the published notes for the versions after the one the app last showed, newest first, in
 * the reader's language, or in English for a version not translated yet.
 */
final class ReleaseNoteController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/']]);
        $since = $data['since'] ?? '0.0.0';
        $language = app()->getLocale();

        $notes = ReleaseNote::query()->whereNotNull('published_at')->whereIn('language', array_unique([$language, 'en']))->get()
            ->filter(fn (ReleaseNote $note) => version_compare($note->version, $since, '>'))
            ->groupBy('version')
            ->map(fn ($versions) => $versions->firstWhere('language', $language) ?? $versions->firstWhere('language', 'en'))
            ->filter()
            ->sortByDesc(fn (ReleaseNote $note) => $note->version, SORT_NATURAL)
            ->map(fn (ReleaseNote $note) => ['version' => $note->version, 'language' => $note->language, 'body' => $note->body])
            ->values();

        return response()->json(['data' => $notes]);
    }
}
