<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Team\AcceptInvitation;
use App\Actions\Team\InviteMember;
use App\Actions\Team\ManageMembers;
use App\Actions\Team\TeamDirectory;
use App\Actions\Team\TeamRules;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Api\AccountPayload;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The team of a business: who is in it, the invitations waiting, inviting, changing roles and removing people. Anyone
 * in the business may look; the owner and managers change it (TeamRules). Behind the `members` feature switch.
 */
final class TeamController
{
    public function index(Request $request, CurrentTenant $current): JsonResponse
    {
        $tenant = $this->tenant($current);
        $entitlement = Entitlements::for($tenant)->check(Feature::Members);
        $role = $request->user()->roleIn($tenant->id);

        return response()->json(['data' => [
            'members' => array_map(fn (array $member) => [...$member, 'role' => $member['role']->value], TeamDirectory::members($tenant)),
            'invitations' => TenantInvitation::query()->open()->with('invitedBy')->latest()->get()->map->toPublicArray()->all(),
            'can_manage' => TeamRules::canManage($role),
            'assignable_roles' => array_map(fn (TenantRole $r) => $r->value, TeamRules::assignable($role)),
            'limit' => $entitlement->limit(),
            'used' => $entitlement->used(),
        ]]);
    }

    public function invite(Request $request, CurrentTenant $current, InviteMember $invite): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $made = $invite->handle($this->tenant($current), $request->user(), $data['role'], $data);

        return response()->json(['data' => ['invitation' => $made['invitation']->load('invitedBy')->toPublicArray(), 'url' => $made['url']]], 201);
    }

    public function revoke(Request $request, CurrentTenant $current, TenantInvitation $invitation, ManageMembers $manage): Response
    {
        $manage->revoke($this->tenant($current), $request->user(), $invitation);

        return response()->noContent();
    }

    public function update(Request $request, CurrentTenant $current, string $member, ManageMembers $manage): JsonResponse
    {
        $tenant = $this->tenant($current);
        $data = $request->validate(['role' => ['required', 'string']]);
        $person = $tenant->users()->whereKey($member)->firstOrFail();

        $role = $manage->changeRole($tenant, $request->user(), $person, $data['role']);

        $member = collect(TeamDirectory::members($tenant))->firstWhere('id', $person->id) ?? abort(404);

        return response()->json(['data' => [...$member, 'role' => $role->value]]);
    }

    public function remove(Request $request, CurrentTenant $current, string $member, ManageMembers $manage): Response
    {
        $tenant = $this->tenant($current);
        $manage->remove($tenant, $request->user(), $tenant->users()->whereKey($member)->firstOrFail());

        return response()->noContent();
    }

    /** Joins the business behind a link, for someone already signed in to the app; answers the account as it now is. */
    public function accept(Request $request, AcceptInvitation $accept): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $tenant = $accept->handle($data['token'], $request->user());

        return response()->json(['data' => AccountPayload::for($request->user()->fresh() ?? $request->user(), $tenant)]);
    }

    private function tenant(CurrentTenant $current): Tenant
    {
        return $current->get() ?? abort(403);
    }
}
