<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Two-factor authentication settings. Fortify owns the actions; this only renders the current state. */
final class SecurityController
{
    public function show(Request $request): View
    {
        return view('account.security', [
            'user' => $request->user(),
            // The phones signed in with the app (Win Plan PP16); API tokens for other software are not sign-ins.
            'devices' => $request->user()->tokens()->where('abilities', 'like', '%"app"%')->orderByDesc('last_used_at')->orderByDesc('created_at')->get(),
        ]);
    }

    public function signOutDevice(Request $request, string $device): RedirectResponse
    {
        $request->user()->tokens()->where('abilities', 'like', '%"app"%')->whereKey($device)->firstOrFail()->delete();

        return redirect()->route('security')->with('status', __('That phone is signed out.'));
    }

    /** Behind password confirmation: recovery codes are as good as a password for getting in. */
    public function recoveryCodes(Request $request): View
    {
        abort_unless($request->user()->hasConfirmedTwoFactor(), 404);

        return view('account.recovery-codes', ['codes' => $request->user()->recoveryCodes()]);
    }
}
