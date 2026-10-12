<?php

namespace App\Http\Controllers\Admin;

use App\Mail\MailSettings;
use App\Notifications\Catalog;
use App\Notifications\Channels;
use App\Notifications\Push\PushSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Notifications for everyone (Sprint 3 Task 1b): which channels are set up (pushes through Firebase, e-mail through the
 * owner's SMTP server, text messages once a provider exists), the Firebase key, and which kind of notice may use which
 * channel. Super admins only.
 */
final class NotificationsController
{
    public function index(Channels $channels, PushSettings $push, MailSettings $mail): View
    {
        Gate::authorize('manage-plans');

        return view('admin.notifications.index', [
            'kinds' => Catalog::KINDS,
            'allowed' => $channels->allowed(),
            'available' => $channels->available(),
            'pushProject' => $push->project(),
            'mailHost' => $mail->get()['host'] ?? null,
        ]);
    }

    public function update(Request $request, Channels $channels): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $data = $request->validate(['allowed' => ['sometimes', 'array'], 'allowed.*' => ['array']]);
        $channels->setAllowed((array) ($data['allowed'] ?? []), $request->user());

        return back()->with('status', __('Notification settings saved.'));
    }

    /** The Firebase service account, pasted as Google gives it, or removed. */
    public function push(Request $request, PushSettings $push): RedirectResponse
    {
        Gate::authorize('manage-plans');

        if ($request->boolean('remove')) {
            $push->save(null, $request->user());

            return back()->with('status', __('The Firebase key was removed. Pushes are written to the log.'));
        }

        $data = $request->validate(['service_account' => ['required', 'string', 'max:20000']]);
        if (! PushSettings::valid(json_decode($data['service_account'], true))) {
            throw ValidationException::withMessages(['service_account' => __('Paste the whole service account file from Firebase: it names the project, the account and its private key.')]);
        }
        $push->save($data['service_account'], $request->user());

        return back()->with('status', __('The Firebase key is saved. Pushes go to phones now.'));
    }
}
