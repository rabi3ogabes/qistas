<?php

use App\Auth\TeamTwoFactor;
use App\Models\AuditLog;
use App\Models\User;

/*
| Two-factor for the admin team (owner's decision, 2026-10-28): optional. Each person turns it on or off on their own
| security page, and the admin console opens either way. A super admin can require it for the whole team again with one
| switch in Admin → Team security, which lists who uses it. Changing the rule is audited, and a super admin cannot
| require it while their own is off, so nobody locks the team out by accident.
*/

function teamSecurityStaff(string $role = 'super_admin', bool $twoFactor = true, string $name = 'Layla Haddad'): User
{
    return User::factory()->create([
        'name' => $name, 'platform_role' => $role,
        'two_factor_secret' => $twoFactor ? encrypt('JBSWY3DPEHPK3PXP') : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);
}

describe('the console', function () {
    it('opens for an admin without two-factor while the team rule is off, as it is at first', function (string $role) {
        expect(app(TeamTwoFactor::class)->required())->toBeFalse();

        $this->actingAs(teamSecurityStaff($role, twoFactor: false))->get('/admin')->assertOk();
    })->with(['admin', 'super_admin']);

    it('sends an admin without two-factor to set it up once the team rule is on', function () {
        requireTeamTwoFactor();

        $this->actingAs(teamSecurityStaff('admin', twoFactor: false))->get('/admin')
            ->assertRedirect(route('security'))
            ->assertSessionHas('warning', 'Turn on two-factor authentication to use the admin console.');
        $this->actingAs(teamSecurityStaff('admin', twoFactor: true, name: 'Sami Odeh'))->get('/admin')->assertOk();
    });

    it('lets an admin turn their own two-factor off and still open the console', function () {
        $admin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->delete(url('/user/two-factor-authentication'))->assertRedirect();

        expect($admin->fresh()->hasConfirmedTwoFactor())->toBeFalse()
            ->and(AuditLog::where('action', 'two_factor.disabled')->where('user_id', $admin->id)->exists())->toBeTrue();
        $this->actingAs($admin->fresh())->get('/admin')->assertOk();
    });

    it('asks for the password again before two-factor can be turned off', function () {
        $admin = User::factory()->platformAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->delete(url('/user/two-factor-authentication'))->assertRedirect(route('password.confirm'));

        expect($admin->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });
});

describe('the team rule', function () {
    it('is required by a super admin, written to the audit log, and lifted again', function () {
        $boss = teamSecurityStaff();

        $this->actingAs($boss)->put(route('admin.security.update'), ['required' => '1'])
            ->assertRedirect(route('admin.security.index'))
            ->assertSessionHas('status', 'Two-factor is now required for everyone on the team.');
        expect(app(TeamTwoFactor::class)->required())->toBeTrue();

        $this->actingAs($boss)->put(route('admin.security.update'), ['required' => '0'])
            ->assertSessionHas('status', 'Two-factor is now optional: each person decides on their own security page.');
        expect(app(TeamTwoFactor::class)->required())->toBeFalse()
            ->and(AuditLog::where('action', 'admin.team_two_factor_changed')->where('user_id', $boss->id)->orderBy('created_at')->pluck('changes')->all())
            ->toBe([['required' => true], ['required' => false]]);
    });

    it('cannot be required by a super admin whose own two-factor is off, so nobody locks themselves out', function () {
        $boss = teamSecurityStaff(twoFactor: false);

        $this->actingAs($boss)->put(route('admin.security.update'), ['required' => '1'])
            ->assertSessionHasErrors(['required' => 'Turn on your own two-factor first, so the rule does not lock you out.']);

        expect(app(TeamTwoFactor::class)->required())->toBeFalse()
            ->and(AuditLog::where('action', 'admin.team_two_factor_changed')->exists())->toBeFalse();
    });

    it('is a super admin’s decision; other staff may look', function () {
        $admin = teamSecurityStaff('admin');

        $this->actingAs($admin)->get(route('admin.security.index'))->assertOk()
            ->assertSee('Only a super admin can change this.')
            ->assertDontSee('Save the rule');
        $this->actingAs($admin)->put(route('admin.security.update'), ['required' => '1'])->assertForbidden();
        expect(app(TeamTwoFactor::class)->required())->toBeFalse();
    });

    it('refuses anything but on or off', function () {
        $this->actingAs(teamSecurityStaff())->put(route('admin.security.update'), ['required' => 'maybe'])->assertSessionHasErrors('required');
    });
});

describe('the page', function () {
    it('lists the team with who uses two-factor, and says how many do', function () {
        $boss = teamSecurityStaff(name: 'Layla Haddad');
        teamSecurityStaff('admin', twoFactor: false, name: 'Sami Odeh');
        User::factory()->create(['name' => 'Omar Customer']);

        $this->actingAs($boss)->get(route('admin.security.index'))->assertOk()
            ->assertSee('Team security')
            ->assertSee('Layla Haddad')->assertSee('Sami Odeh')->assertDontSee('Omar Customer')
            ->assertSee('1 of 2 people on the team use two-factor.')
            ->assertSee('Save the rule');
    });

    it('is in the admin menu, and not there for anyone outside the team', function () {
        $this->actingAs(teamSecurityStaff())->get(route('admin.home'))->assertSee(route('admin.security.index'), false);

        $this->actingAs(User::factory()->create())->get(route('admin.security.index'))->assertNotFound();
    });
});
