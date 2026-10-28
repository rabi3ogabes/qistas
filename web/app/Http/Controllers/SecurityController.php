<?php

namespace App\Http\Controllers;

use App\Auth\TeamTwoFactor;
use App\Auth\TrustedDevice;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/** Two-factor authentication settings. Fortify owns turning it on and off; this renders the state and the extras. */
final class SecurityController
{
    public function show(Request $request, TrustedDevice $devices, TeamTwoFactor $team): View
    {
        return view('account.security', [
            'user' => $request->user(),
            // Two-factor is each person's choice, unless a super admin requires it for the admin team.
            'mustUseTwoFactor' => $request->user()->isPlatformAdmin() && $team->required(),
            'trustedUntil' => $devices->trustedUntil($request, $request->user()),
            // The phones signed in with the app (Win Plan PP16); API tokens for other software are not sign-ins.
            'devices' => $request->user()->tokens()->where('abilities', 'like', '%"app"%')->orderByDesc('last_used_at')->orderByDesc('created_at')->get(),
        ]);
    }

    public function signOutDevice(Request $request, string $device): RedirectResponse
    {
        $request->user()->tokens()->where('abilities', 'like', '%"app"%')->whereKey($device)->firstOrFail()->delete();

        return redirect()->route('security')->with('status', __('That phone is signed out.'));
    }

    /**
     * A fresh key while setup is not confirmed yet (a key that was shown to someone else, or a lost scan). A confirmed
     * key is never replaced here: turning two-factor authentication off and on again is the deliberate way.
     */
    public function restartTwoFactor(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasConfirmedTwoFactor()) {
            return redirect()->route('security')->with('warning', __('Two-factor authentication is already on. Turn it off first to set it up with a new key.'));
        }

        $enable($user, true);
        Audit::record('two_factor.setup_restarted', userId: $user->id);

        return redirect()->route('security')->with('status', __('Here is a new key. Scan the new QR code; the old one no longer works.'));
    }

    public function forgetTrustedDevices(Request $request, TrustedDevice $devices): RedirectResponse
    {
        $devices->forgetAll($request->user());

        return redirect()->route('security')->with('status', __('Every device will ask for a code at the next sign-in.'));
    }

    /** Behind password confirmation: recovery codes are as good as a password for getting in. */
    public function recoveryCodes(Request $request): View
    {
        abort_unless($request->user()->hasConfirmedTwoFactor(), 404);

        return view('account.recovery-codes', ['codes' => $request->user()->recoveryCodes()]);
    }
}
