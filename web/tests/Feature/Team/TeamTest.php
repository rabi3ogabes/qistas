<?php

use App\Entitlements\Feature;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * Win Plan PP11 (SC05): the owner invites a partner, an accountant or a collector, chooses what each may do, and removes
 * them whenever they want. Free keeps one person; Pro includes three, with no purchase per member.
 */

beforeEach(fn () => switchOn(Feature::Members));

/** An owner on Pro (three people), signed in to the API. */
function teamOwner(): array
{
    [$user, $tenant] = apiOwner(['name' => 'Al-Fares Electronics']);
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    return [$user, $tenant];
}

/** The token from an invitation link. */
function tokenFrom(string $url): string
{
    return basename(parse_url($url, PHP_URL_PATH));
}

describe('inviting', function () {
    it('makes a link the owner can share, and keeps only a fingerprint of its secret', function () {
        teamOwner();

        $response = $this->postJson('/api/v1/team/invitations', ['role' => 'collector', 'name' => 'Omar', 'phone' => '+966 55 123 4567'])
            ->assertCreated()
            ->assertJsonPath('data.invitation.role', 'collector')
            ->assertJsonPath('data.invitation.name', 'Omar');

        $url = $response->json('data.url');
        expect($url)->toStartWith(url('/invite/'));
        $token = tokenFrom($url);
        expect(strlen($token))->toBeGreaterThanOrEqual(32)
            ->and(DB::table('tenant_invitations')->where('token_hash', $token)->exists())->toBeFalse()
            ->and(DB::table('tenant_invitations')->where('token_hash', hash('sha256', $token))->exists())->toBeTrue();
    });

    it('lets the owner and a manager invite, and nobody else', function (string $role, int $status) {
        [, $tenant] = teamOwner();
        apiMember($role, $tenant);

        $this->postJson('/api/v1/team/invitations', ['role' => 'viewer'])->assertStatus($status);
    })->with([['manager', 201], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('never lets a manager hand out the manager role, and nobody can invite a second owner', function () {
        [, $tenant] = teamOwner();
        $this->postJson('/api/v1/team/invitations', ['role' => 'owner'])->assertStatus(422);

        apiMember('manager', $tenant);
        $this->postJson('/api/v1/team/invitations', ['role' => 'manager'])->assertForbidden();
    });

    it('keeps a Free workspace to its one person', function () {
        apiOwner();

        $this->postJson('/api/v1/team/invitations', ['role' => 'viewer'])
            ->assertStatus(402)->assertJsonPath('error.code', 'limit_reached')->assertJsonPath('error.feature', 'members');
    });

    it('counts invitations still waiting, so Pro stops at three people', function () {
        teamOwner();

        $this->postJson('/api/v1/team/invitations', ['role' => 'viewer'])->assertCreated();
        $this->postJson('/api/v1/team/invitations', ['role' => 'collector'])->assertCreated();
        $this->postJson('/api/v1/team/invitations', ['role' => 'accountant'])->assertStatus(402)->assertJsonPath('error.limit', 3);
    });

    it('lists the people and the invitations waiting', function () {
        [, $tenant] = teamOwner();
        memberAs('collector', $tenant)->forceFill(['name' => 'Omar Khalil'])->save();
        $this->postJson('/api/v1/team/invitations', ['role' => 'viewer', 'name' => 'Sara'])->assertCreated();

        $team = $this->getJson('/api/v1/team')->assertOk();

        expect(array_column($team->json('data.members'), 'role'))->toBe(['owner', 'collector'])
            ->and($team->json('data.members.1.name'))->toBe('Omar Khalil')
            ->and($team->json('data.invitations.0.name'))->toBe('Sara')
            ->and($team->json('data.invitations.0'))->not->toHaveKey('token_hash')
            ->and($team->json('data.can_manage'))->toBeTrue()
            ->and($team->json('data.limit'))->toBe(3);
    });
});

describe('joining', function () {
    it('lets someone with an account join with the role they were given, once', function () {
        teamOwner();
        $url = $this->postJson('/api/v1/team/invitations', ['role' => 'collector'])->json('data.url');
        [$omar, $own] = owner();
        Sanctum::actingAs($omar, ['app']);

        $this->postJson('/api/v1/invitations/accept', ['token' => tokenFrom($url)])
            ->assertOk()->assertJsonPath('data.tenant.name', 'Al-Fares Electronics')->assertJsonPath('data.tenant.role', 'collector');

        $this->getJson('/api/v1/me')->assertJsonPath('data.tenant.name', 'Al-Fares Electronics');
        $this->postJson('/api/v1/invitations/accept', ['token' => tokenFrom($url)])->assertStatus(410)->assertJsonPath('error.code', 'invitation_invalid');
        expect($omar->fresh()->roleIn($own->id))->not->toBeNull('they keep their own business too');
    });

    it('refuses a link after 7 days or once revoked', function () {
        $this->travelTo('2026-10-11 10:00:00');
        teamOwner();
        $late = $this->postJson('/api/v1/team/invitations', ['role' => 'viewer'])->json('data.url');
        $revoked = $this->postJson('/api/v1/team/invitations', ['role' => 'viewer'])->json('data');
        $this->deleteJson('/api/v1/team/invitations/'.$revoked['invitation']['id'])->assertNoContent();
        [$someone] = owner();
        Sanctum::actingAs($someone, ['app']);

        $this->postJson('/api/v1/invitations/accept', ['token' => tokenFrom($revoked['url'])])->assertStatus(410);
        $this->travelTo('2026-10-18 10:01:00');
        $this->postJson('/api/v1/invitations/accept', ['token' => tokenFrom($late)])->assertStatus(410);
    });

    it('never lets one business touch another business’s invitations', function () {
        teamOwner();
        $mine = $this->postJson('/api/v1/team/invitations', ['role' => 'viewer'])->json('data.invitation.id');

        [$other] = owner();
        Sanctum::actingAs($other, ['app']);
        $this->deleteJson("/api/v1/team/invitations/{$mine}")->assertNotFound();
    });
});

describe('changing and removing', function () {
    it('lets the owner change a role, and records it', function () {
        [, $tenant] = teamOwner();
        $omar = memberAs('collector', $tenant);

        $this->putJson("/api/v1/team/members/{$omar->id}", ['role' => 'manager'])->assertOk()->assertJsonPath('data.role', 'manager');

        expect($omar->fresh()->roleIn($tenant->id)?->value)->toBe('manager')
            ->and(AuditLog::query()->where('action', 'team.role_changed')->sole()->changes)->toBe(['role' => ['from' => 'collector', 'to' => 'manager']]);
    });

    it('never changes or removes the owner, and a manager cannot touch another manager', function () {
        [$owner, $tenant] = teamOwner();
        $peer = memberAs('manager', $tenant);
        apiMember('manager', $tenant);

        $this->putJson("/api/v1/team/members/{$owner->id}", ['role' => 'viewer'])->assertForbidden();
        $this->deleteJson("/api/v1/team/members/{$owner->id}")->assertForbidden();
        $this->deleteJson("/api/v1/team/members/{$peer->id}")->assertForbidden();
        $this->putJson("/api/v1/team/members/{$peer->id}", ['role' => 'viewer'])->assertForbidden();
    });

    it('removes a member at once: they are signed out and can no longer open the books', function () {
        [, $tenant] = teamOwner();
        customerIn($tenant, ['name' => 'Ahmad']);
        $omar = memberAs('collector', $tenant);
        $omar->createToken('Omar’s phone', ['app']);

        $this->deleteJson("/api/v1/team/members/{$omar->id}")->assertNoContent();

        expect($omar->fresh()->roleIn($tenant->id))->toBeNull()
            ->and($omar->tokens()->count())->toBe(0)
            ->and(AuditLog::query()->where('action', 'team.member_removed')->count())->toBe(1);

        Sanctum::actingAs($omar->fresh(), ['app']);
        $this->getJson('/api/v1/customers')->assertForbidden();
    });

    it('answers 404 for someone who is not in the business', function () {
        teamOwner();
        [$stranger] = owner();

        $this->deleteJson("/api/v1/team/members/{$stranger->id}")->assertNotFound();
    });
});

describe('the switch', function () {
    it('keeps the team closed while the platform has it off', function () {
        DB::table('platform_features')->where('feature_key', 'members')->update(['state' => 'off']);
        teamOwner();

        $this->getJson('/api/v1/team')->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });
});

describe('on the web', function () {
    it('shows the team and makes an invitation link to share', function () {
        [$user, $tenant] = owner(['name' => 'Al-Fares Electronics']);
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

        $this->actingAs($user)->get('/app/team')->assertOk()->assertSee('Team')->assertSee($user->name);

        $this->actingAs($user)->post('/app/team/invitations', ['role' => 'collector', 'name' => 'Omar'])
            ->assertRedirect('/app/team')->assertSessionHas('invitation_url');
    });

    it('lets a new person join from the link with a new login, without opening a business of their own', function () {
        [$owner, $tenant] = owner(['name' => 'Al-Fares Electronics']);
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        $url = $this->actingAs($owner)->post('/app/team/invitations', ['role' => 'collector'])->getSession()->get('invitation_url');
        auth()->logout();

        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('Al-Fares Electronics')->assertSee('Collector');

        $this->post(parse_url($url, PHP_URL_PATH).'/register', [
            'name' => 'Omar Khalil', 'email' => 'omar@example.com', 'password' => TEST_PASSWORD, 'password_confirmation' => TEST_PASSWORD, 'terms' => '1',
        ])->assertRedirect('/app');

        $omar = User::query()->where('email', 'omar@example.com')->sole();
        expect($omar->roleIn($tenant->id)?->value)->toBe('collector')
            ->and($omar->tenants()->count())->toBe(1)
            ->and(Tenant::query()->count())->toBe(1);
        $this->assertAuthenticatedAs($omar);
    });

    it('lets someone who is signed in join with one tap', function () {
        [$owner, $tenant] = owner(['name' => 'Al-Fares Electronics']);
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        $url = $this->actingAs($owner)->post('/app/team/invitations', ['role' => 'viewer'])->getSession()->get('invitation_url');
        [$sara] = owner();

        $this->actingAs($sara)->post(parse_url($url, PHP_URL_PATH).'/accept')->assertRedirect('/app');

        expect($sara->fresh()->roleIn($tenant->id)?->value)->toBe('viewer')
            ->and($sara->fresh()->current_tenant_id)->toBe($tenant->id);
        expect(asTenant($tenant, fn () => Customer::count()))->toBe(0);
    });
});
