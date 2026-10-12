<?php

namespace App\Http\Controllers\Admin;

use App\Mail\MailSettings;
use App\Mail\SettingsCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * E-mail (Sprint 3, decision D1): the owner's SMTP server, set by a super admin. The password is never shown back, only
 * that one is saved; a test e-mail goes to the admin's own address.
 */
final class MailController
{
    public function index(MailSettings $settings): View
    {
        Gate::authorize('manage-plans');
        $smtp = $settings->get();

        return view('admin.mail.index', [
            'smtp' => $smtp === null ? null : [...$smtp, 'password' => $smtp['password'] !== null],
            'serverDecides' => $settings->serverDecides(),
        ]);
    }

    public function update(Request $request, MailSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');

        if ($request->boolean('remove')) {
            $settings->remove($request->user());

            return back()->with('status', __('E-mail settings removed. E-mails go to the log again.'));
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'security' => ['required', Rule::in(MailSettings::SECURITY)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:80'],
        ]);
        $settings->save($data, $request->user());

        return back()->with('status', __('E-mail settings saved.'));
    }

    /** Sends one e-mail to the admin; a server that cannot be reached is reported in plain words, not thrown. */
    public function test(Request $request, MailSettings $settings): RedirectResponse
    {
        Gate::authorize('manage-plans');
        $settings->apply();

        try {
            Mail::to($request->user())->send(new SettingsCheck);
        } catch (Throwable $e) {
            return back()->with('warning', __('The test e-mail could not be sent: :reason', ['reason' => Str::limit($e->getMessage(), 200)]));
        }

        return back()->with('status', __('A test e-mail is on its way to :email.', ['email' => $request->user()?->email]));
    }
}
