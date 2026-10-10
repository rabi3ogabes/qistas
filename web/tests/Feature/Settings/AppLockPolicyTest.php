<?php

use App\Models\AuditLog;

/*
 * Win Plan PP2: a phone locks Qistas behind the owner's fingerprint, face or PIN. The lock itself lives on the phone;
 * the business can require it of everyone who opens its books, and that choice lives here.
 */

describe('in the API', function () {
    it('tells the app whether the business requires the lock', function () {
        [, $tenant] = apiOwner();

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.require_app_lock', false);

        $tenant->forceFill(['require_app_lock' => true])->save();

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.tenant.require_app_lock', true);
    });

    it('lets the owner require it, records who did, and answers with the new value', function () {
        [$user, $tenant] = apiOwner();

        $this->putJson('/api/v1/workspace/security', ['require_app_lock' => true])
            ->assertOk()->assertExactJson(['data' => ['require_app_lock' => true]]);

        expect($tenant->fresh()->require_app_lock)->toBeTrue();
        $audit = AuditLog::query()->where('action', 'workspace.app_lock_policy_changed')->sole();
        expect($audit->user_id)->toBe($user->id)->and($audit->changes)->toBe(['require_app_lock' => ['from' => false, 'to' => true]]);
    });

    it('lets an owner and a manager change it, and nobody else', function (string $role, int $status) {
        [, $tenant] = owner();
        apiMember($role, $tenant);

        $this->putJson('/api/v1/workspace/security', ['require_app_lock' => true])->assertStatus($status);

        expect($tenant->fresh()->require_app_lock)->toBe($status === 200);
    })->with([['owner', 200], ['manager', 200], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('wants a clear yes or no', function () {
        apiOwner();

        $this->putJson('/api/v1/workspace/security', ['require_app_lock' => 'perhaps'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['require_app_lock']]]);
    });

    it('changes only the signed-in workspace', function () {
        [, $mine] = apiOwner();
        [, $theirs] = owner();

        $this->putJson('/api/v1/workspace/security', ['require_app_lock' => true])->assertOk();

        expect($mine->fresh()->require_app_lock)->toBeTrue()->and($theirs->fresh()->require_app_lock)->toBeFalse();
    });
});

describe('on the web', function () {
    it('offers the switch to the owner on the settings page', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/settings/tools')->assertOk()
            ->assertSee('Require the app lock')
            ->assertSee('name="require_app_lock"', false);
    });

    it('saves it from the settings page', function () {
        [$user, $tenant] = owner();

        $this->actingAs($user)->put('/app/settings/security', ['require_app_lock' => '1'])
            ->assertRedirect('/app/settings/tools')->assertSessionHas('status');

        expect($tenant->fresh()->require_app_lock)->toBeTrue();
    });

    it('shows the choice to other members without letting them change it', function () {
        [, $tenant] = owner();
        $collector = memberAs('collector', $tenant);

        $this->actingAs($collector)->get('/app/settings/tools')->assertOk()
            ->assertSee('Require the app lock')->assertDontSee('name="require_app_lock"', false);
        $this->actingAs($collector)->put('/app/settings/security', ['require_app_lock' => '1'])->assertForbidden();

        expect($tenant->fresh()->require_app_lock)->toBeFalse();
    });
});
