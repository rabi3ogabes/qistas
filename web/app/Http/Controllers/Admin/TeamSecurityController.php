<?php

namespace App\Http\Controllers\Admin;

use App\Auth\TeamTwoFactor;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Team security: whether the admin team must use two-factor, and who on the team uses it. Any platform staff may look;
 * a super admin changes the rule ('manage-team-security').
 */
final class TeamSecurityController
{
    public function index(Request $request, TeamTwoFactor $team): View
    {
        $staff = User::query()->whereIn('platform_role', User::ADMIN_ROLES)
            ->orderByRaw("case when platform_role = 'super_admin' then 0 else 1 end")->orderBy('name')
            ->get(['id', 'name', 'email', 'platform_role', 'status', 'two_factor_secret', 'two_factor_confirmed_at']);

        return view('admin.security.index', [
            'required' => $team->required(),
            'staff' => $staff,
            'protected' => $staff->filter(fn (User $user) => $user->hasConfirmedTwoFactor())->count(),
            'canChange' => Gate::allows('manage-team-security'),
            'me' => $request->user(),
        ]);
    }

    public function update(Request $request, TeamTwoFactor $team): RedirectResponse
    {
        Gate::authorize('manage-team-security');
        $required = $request->validate(['required' => ['required', 'boolean']], ['required.*' => __('Choose optional or required.')])['required'];

        $team->set((bool) $required, $request->user());

        return redirect()->route('admin.security.index')->with('status', $team->required()
            ? __('Two-factor is now required for everyone on the team.')
            : __('Two-factor is now optional: each person decides on their own security page.'));
    }
}
