<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/** Two-factor authentication settings. Fortify owns the actions; this only renders the current state. */
final class SecurityController
{
    public function show(Request $request): View
    {
        return view('account.security', ['user' => $request->user()]);
    }

    /** Behind password confirmation: recovery codes are as good as a password for getting in. */
    public function recoveryCodes(Request $request): View
    {
        abort_unless($request->user()->hasConfirmedTwoFactor(), 404);

        return view('account.recovery-codes', ['codes' => $request->user()->recoveryCodes()]);
    }
}
