<?php

namespace App\Http\Controllers\Workspace;

use App\Actions\Team\InviteMember;
use App\Actions\Team\ManageMembers;
use App\Actions\Team\TeamDirectory;
use App\Actions\Team\TeamRules;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The Team page of the web app: the people, their roles, invitations by link. Same rules as the API (TeamRules). */
final class TeamController
{
    public function index(Request $request, CurrentTenant $current): View
    {
        $tenant = $this->tenant($current);
        $role = $request->user()->roleIn($tenant->id);

        return view('app.team', [
            'members' => TeamDirectory::members($tenant),
            'tenant' => $tenant,
            'invitations' => TenantInvitation::query()->open()->with('invitedBy')->latest()->get(),
            'myRole' => $role,
            'canManage' => TeamRules::canManage($role),
            'assignable' => TeamRules::assignable($role),
            'entitlement' => Entitlements::for($tenant)->check(Feature::Members),
        ]);
    }

    public function invite(Request $request, CurrentTenant $current, InviteMember $invite): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $made = $invite->handle($this->tenant($current), $request->user(), $data['role'], $data);

        // The link is shown once, right after it is made: only its fingerprint is kept.
        return redirect()->route('app.team.index')->with('invitation_url', $made['url'])->with('status', __('Invitation ready. Share the link below.'));
    }

    public function revoke(Request $request, CurrentTenant $current, TenantInvitation $invitation, ManageMembers $manage): RedirectResponse
    {
        $manage->revoke($this->tenant($current), $request->user(), $invitation);

        return redirect()->route('app.team.index')->with('status', __('Invitation withdrawn.'));
    }

    public function update(Request $request, CurrentTenant $current, string $member, ManageMembers $manage): RedirectResponse
    {
        $tenant = $this->tenant($current);
        $manage->changeRole($tenant, $request->user(), $tenant->users()->whereKey($member)->firstOrFail(), (string) $request->input('role'));

        return redirect()->route('app.team.index')->with('status', __('Role changed.'));
    }

    public function remove(Request $request, CurrentTenant $current, string $member, ManageMembers $manage): RedirectResponse
    {
        $tenant = $this->tenant($current);
        $manage->remove($tenant, $request->user(), $tenant->users()->whereKey($member)->firstOrFail());

        return redirect()->route('app.team.index')->with('status', __('Removed from the team.'));
    }

    private function tenant(CurrentTenant $current): Tenant
    {
        return $current->get() ?? abort(403);
    }
}
