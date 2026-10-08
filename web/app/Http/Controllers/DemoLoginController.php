<?php

namespace App\Http\Controllers;

use App\Sandbox\DemoAccess;
use App\Sandbox\DemoBusy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The demo buttons on the sign-in page: one press, a fresh throw-away account, signed in. See DemoAccess. */
final class DemoLoginController
{
    public function start(Request $request, string $persona, DemoAccess $demo): RedirectResponse
    {
        abort_unless(DemoAccess::enabled(), 404);

        try {
            $user = $demo->start($persona);
        } catch (DemoBusy) {
            return redirect()->route('login')->with('warning', __('The demo is busy right now. Please try again in a few minutes.'));
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('app.dashboard')->with('status', __('Welcome to the demo. It is sample data, and it is cleared a few hours after you start.'));
    }

    /** "Create my free account": ends the demo, deletes what it made, and goes to sign-up. */
    public function leave(Request $request, DemoAccess $demo): RedirectResponse
    {
        $user = $request->user();
        abort_if($user === null || $user->demo_expires_at === null, 404);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $demo->forget($user);

        return redirect()->route('register');
    }
}
