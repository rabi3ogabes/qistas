<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Reminders\DueReminders;
use App\Reminders\ReminderTemplates;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Remind everyone (Win Plan PP9): the checklist of who pays today, or who is late, each with the message ready for
 * WhatsApp; and the business's own wording of those messages, in five languages, which owners and managers change.
 */
final class ReminderController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function dueToday(Request $request, DueReminders $reminders): JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(['due', 'late'])],
            'language' => ['sometimes', Rule::in((array) config('qistas.locales'))],
        ]);

        return response()->json(['data' => $reminders->rows($tenant, $data['scope'] ?? 'due', $data['language'] ?? app()->getLocale())]);
    }

    public function templates(ReminderTemplates $templates): JsonResponse
    {
        return response()->json(['data' => $templates->all($this->current->get() ?? abort(404))]);
    }

    public function updateTemplate(Request $request, string $key, ReminderTemplates $templates): JsonResponse
    {
        abort_unless(in_array($key, ReminderTemplates::KEYS, true), 404);
        $tenant = $this->tenant();
        abort_unless($request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false, 403);

        $data = $request->validate([
            'language' => ['required', Rule::in((array) config('qistas.locales'))],
            'body' => ['present', 'nullable', 'string', 'max:'.ReminderTemplates::MAX_LENGTH],
        ]);
        $templates->set($tenant, $key, $data['language'], (string) ($data['body'] ?? ''), $request->user());

        return response()->json(['data' => collect($templates->all($tenant))->where('key', $key)->where('language', $data['language'])->first()]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->current->get() ?? abort(404);
        Entitlements::for($tenant)->assertEnabled(Feature::InstalmentAlerts);

        return $tenant;
    }
}
