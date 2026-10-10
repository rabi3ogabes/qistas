<?php

namespace App\Http\Controllers\Workspace;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\TagRequest;
use App\Models\Tag;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The business's tags (Win Plan PP12): add, rename, recolour and let go of them. Removing one never touches a customer. */
final class TagController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request): View
    {
        $this->assertOn();

        return view('app.tags', [
            'tags' => Tag::query()->withCount('customers')->orderByRaw('LOWER(name)')->get(),
            'canEdit' => $request->user()?->roleIn((string) $this->current->id())?->canWrite() ?? false,
        ]);
    }

    public function store(TagRequest $request): RedirectResponse
    {
        $this->assertOn();
        Tag::create(['name' => $request->validated('name'), 'colour' => $request->validated('colour') ?? 'grey']);

        return redirect()->route('app.tags.index')->with('status', __('Tag added.'));
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        $this->assertOn();
        $tag->update(['name' => $request->validated('name'), 'colour' => $request->validated('colour') ?? $tag->colour]);

        return redirect()->route('app.tags.index')->with('status', __('Tag saved.'));
    }

    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        abort_unless($request->user()?->roleIn((string) $this->current->id())?->canWrite() ?? false, 403);
        $this->assertOn();
        $tag->customers()->detach();
        $tag->delete();

        return redirect()->route('app.tags.index')->with('status', __('Tag removed. Its customers are unchanged.'));
    }

    private function assertOn(): void
    {
        Entitlements::for($this->current->get() ?? abort(404))->assertEnabled(Feature::CustomerTags);
    }
}
