<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\TagRequest;
use App\Models\Tag;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Tags that group customers and filter every list (Win Plan PP12). Anyone in the business may read them; anyone who
 * writes may add, rename and remove them while the customer_tags feature is on. Removing a tag never touches a customer.
 */
final class TagController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(): JsonResponse
    {
        $tags = Tag::query()->withCount('customers')->orderByRaw('LOWER(name)')->get();

        return response()->json(['data' => $tags->map(fn (Tag $tag) => $tag->toSummary() + ['customers' => (int) $tag->customers_count])->values()]);
    }

    public function store(TagRequest $request): JsonResponse
    {
        $this->assertOn();
        $tag = Tag::create(['name' => $request->validated('name'), 'colour' => $request->validated('colour') ?? 'grey']);

        return response()->json(['data' => $tag->toSummary() + ['customers' => 0]], 201);
    }

    public function update(TagRequest $request, Tag $tag): JsonResponse
    {
        $this->assertOn();
        $tag->update(['name' => $request->validated('name'), 'colour' => $request->validated('colour') ?? $tag->colour]);

        return response()->json(['data' => $tag->toSummary() + ['customers' => $tag->customers()->count()]]);
    }

    public function destroy(Tag $tag): Response
    {
        abort_unless(request()->user()?->roleIn((string) $this->current->id())?->canWrite() ?? false, 403);
        $this->assertOn();
        $tag->customers()->detach();
        $tag->delete();

        return response()->noContent();
    }

    private function assertOn(): void
    {
        Entitlements::for($this->current->get() ?? abort(404))->assertEnabled(Feature::CustomerTags);
    }
}
