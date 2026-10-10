<?php

namespace App\Http\Controllers\Workspace;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Reminders\DueReminders;
use App\Reminders\ReminderTemplates;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Remind everyone (Win Plan PP9) on the web: who pays today, or who is late, each a tap away from WhatsApp with the
 * message ready; and, for owners and managers, the business's own wording in each language.
 */
final class ReminderController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request, DueReminders $reminders, ReminderTemplates $templates): View
    {
        $tenant = $this->tenant();
        $scope = $request->query('scope') === 'late' ? 'late' : 'due';
        $locales = (array) config('qistas.locales');
        $language = in_array($request->query('language'), $locales, true) ? (string) $request->query('language') : app()->getLocale();

        $lists = [
            'due' => $reminders->rows($tenant, 'due', app()->getLocale()),
            'late' => $reminders->rows($tenant, 'late', app()->getLocale()),
        ];

        return view('app.reminders', [
            'scope' => $scope,
            'rows' => $lists[$scope],
            'counts' => array_map('count', $lists),
            'currency' => $tenant->currency,
            'canEdit' => $request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false,
            'language' => $language,
            'locales' => $locales,
            'wording' => collect($templates->all($tenant))->where('language', $language)->keyBy('key')->all(),
        ]);
    }

    public function updateTemplate(Request $request, string $key, ReminderTemplates $templates): RedirectResponse
    {
        abort_unless(in_array($key, ReminderTemplates::KEYS, true), 404);
        $tenant = $this->tenant();
        abort_unless($request->user()?->roleIn($tenant->id)?->canManageSettings() ?? false, 403);

        $data = $request->validate([
            'language' => ['required', Rule::in((array) config('qistas.locales'))],
            'body' => ['nullable', 'string', 'max:'.ReminderTemplates::MAX_LENGTH],
        ]);
        $templates->set($tenant, $key, $data['language'], $request->boolean('reset') ? '' : (string) ($data['body'] ?? ''), $request->user());

        return redirect()->route('app.reminders.index', ['language' => $data['language']])
            ->with('status', $request->boolean('reset') ? __('The default wording is back.') : __('Wording saved.'));
    }

    private function tenant(): Tenant
    {
        $tenant = $this->current->get() ?? abort(403);
        Entitlements::for($tenant)->assertEnabled(Feature::InstalmentAlerts);

        return $tenant;
    }
}
