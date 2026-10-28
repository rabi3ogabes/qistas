<?php

namespace App\Http\Controllers\Workspace;

use App\Notifications\Catalog;
use App\Notifications\Channels;
use App\Notifications\Preferences;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A person's own notices on the web (Sprint 3 Task 1b): each kind on or off, and where it reaches them, among the channels
 * the admin allows and that are set up. Billing notices, and the notice of a cash difference at the close of a day, are for
 * the workspace's owners, so only owners see them; the evening reminder is for whoever may close the day (Catalog::forRole).
 */
final class NotificationSettingsController
{
    public function show(Request $request, Channels $channels): View
    {
        $user = $request->user();
        $available = $channels->available();
        $available['email'] = $available['email'] && (bool) $user?->hasVerifiedEmail();

        return view('app.notifications-settings', [
            'kinds' => $this->kinds($request),
            'choices' => Preferences::for((string) $user?->id),
            'allowed' => $channels->allowed(),
            'available' => $available,
            'emailVerified' => (bool) $user?->hasVerifiedEmail(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $kinds = $this->kinds($request);
        $sent = (array) $request->input('kinds', []);

        // Checkboxes say only what is ticked: every kind shown is saved, ticked or not.
        $changes = [];
        foreach (array_keys($kinds) as $kind) {
            $changes[$kind] = [
                'enabled' => (bool) ($sent[$kind]['enabled'] ?? false),
                'channels' => array_map(fn (string $channel) => (bool) ($sent[$kind]['channels'][$channel] ?? false), array_combine(Catalog::CHANNELS, Catalog::CHANNELS)),
            ];
        }
        Preferences::update((string) $request->user()?->id, $changes);

        return back()->with('status', __('Your notification choices are saved.'));
    }

    /** @return array<string, array{group: string, types: list<string>, channels: array{push: bool, email: bool, sms: bool}}> */
    private function kinds(Request $request): array
    {
        $tenant = app(CurrentTenant::class)->get();

        return Catalog::forRole($tenant === null ? null : $request->user()?->roleIn($tenant->id));
    }
}
