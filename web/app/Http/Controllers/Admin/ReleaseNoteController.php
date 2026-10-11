<?php

namespace App\Http\Controllers\Admin;

use App\Models\ReleaseNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "What's new" for each app version (Win Plan PP17), written by staff in every language the app speaks. Saving a version
 * replaces its notes; the app shows them once they are published.
 */
final class ReleaseNoteController
{
    public function index(Request $request): View
    {
        $notes = ReleaseNote::query()->get()->groupBy('version')->sortKeysDesc(SORT_NATURAL);
        $editing = is_string($request->query('version')) ? $notes->get($request->query('version')) : null;

        return view('admin.release-notes.index', [
            'versions' => $notes->map(fn ($rows) => [
                'languages' => $rows->pluck('language')->sort()->values()->all(),
                'published' => $rows->every(fn (ReleaseNote $n) => $n->published_at !== null),
            ]),
            'editing' => $editing?->pluck('body', 'language')->all() ?? [],
            'editingVersion' => $editing !== null ? $request->query('version') : null,
            'editingPublished' => $editing?->every(fn (ReleaseNote $n) => $n->published_at !== null) ?? false,
            'languages' => config('qistas.locales'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/'],
            'body' => ['required', 'array'],
            'body.en' => ['required', 'string', 'max:3000'],
            'body.*' => ['nullable', 'string', 'max:3000'],
            'publish' => ['nullable', 'boolean'],
        ], [
            'version.regex' => __('Write the app version as three numbers, for example 1.16.0.'),
            'body.en.required' => __('Write the English note: it is shown wherever a translation is missing.'),
        ]);

        DB::transaction(function () use ($data): void {
            ReleaseNote::query()->where('version', $data['version'])->delete();
            foreach (config('qistas.locales') as $language) {
                $body = trim((string) ($data['body'][$language] ?? ''));
                if ($body !== '') {
                    (new ReleaseNote)->forceFill([
                        'version' => $data['version'], 'language' => $language, 'body' => $body,
                        'published_at' => ($data['publish'] ?? false) ? now() : null,
                    ])->save();
                }
            }
        });

        return redirect()->route('admin.release-notes.index')->with('status', __('Notes for :version saved.', ['version' => $data['version']]));
    }
}
