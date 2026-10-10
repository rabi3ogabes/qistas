<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureCatalogue;
use App\Entitlements\FeatureControl;
use App\Entitlements\FeatureControlException;
use App\Entitlements\FeatureUsage;
use App\Entitlements\PlatformState;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlatformFeature;
use App\Models\TenantOverride;
use App\Models\User;

/*
| Today's seven features are all core, and a core switch cannot be changed. To prove the machinery that will switch
| the programme's features, three of them are treated as ordinary here (the catalogue is the seam: the real one is
| the enum), with the kind of relationships the programme has: exports touch customers, reports need exports, and
| branding is part of the essentials. They start dark, like every new feature does.
*/

beforeEach(function () {
    $ordinary = [Feature::AdvancedReports, Feature::ExportCsv, Feature::CustomBranding];

    app()->instance(FeatureCatalogue::class, new FeatureCatalogue(
        locked: fn (Feature $f) => ! in_array($f, $ordinary, true),
        touchesCustomers: fn (Feature $f) => $f === Feature::ExportCsv,
        essential: fn (Feature $f) => $f === Feature::CustomBranding,
        dependsOn: fn (Feature $f) => $f === Feature::AdvancedReports ? [Feature::ExportCsv] : [],
    ));

    PlatformFeature::query()->whereIn('feature_key', ['advanced_reports', 'export_csv', 'custom_branding'])->update(['state' => 'off']);
});

function control(): FeatureControl
{
    return app(FeatureControl::class);
}

function stateOf(Feature $feature): string
{
    return PlatformFeature::find($feature->value)->state->value;
}

function controlCode(Closure $call): string
{
    try {
        $call();
    } catch (FeatureControlException $e) {
        return $e->errorCode;
    }

    test()->fail('Expected a FeatureControlException.');
}

function staffMember(string $role = 'super_admin', bool $twoFactor = true): User
{
    return User::factory()->create([
        'platform_role' => $role,
        'two_factor_secret' => $twoFactor ? encrypt('JBSWY3DPEHPK3PXP') : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);
}

describe('switching a feature', function () {
    it('changes the state, remembers who and why, and audits it once', function () {
        $admin = staffMember();

        control()->setState(Feature::ExportCsv, PlatformState::On, null, $admin);

        $row = PlatformFeature::find('export_csv');
        expect($row->state)->toBe(PlatformState::On)->and($row->changed_by_user_id)->toBe($admin->id)->and($row->changed_at)->not->toBeNull();

        $audit = AuditLog::where('action', 'feature.state_changed')->sole();
        expect($audit->user_id)->toBe($admin->id)->and($audit->tenant_id)->toBeNull()
            ->and($audit->changes)->toMatchArray(['feature' => 'export_csv', 'from' => 'off', 'to' => 'on', 'undo' => false, 'actor' => 'admin']);
    });

    it('does nothing, and records nothing, when the state is already what was asked', function () {
        control()->setState(Feature::ExportCsv, PlatformState::Off, null, staffMember());

        expect(AuditLog::where('action', 'feature.state_changed')->count())->toBe(0);
    });

    it('refuses to change a core feature, which every workspace already relies on', function () {
        expect(controlCode(fn () => control()->setState(Feature::Customers, PlatformState::Off, 'trying', staffMember())))->toBe('core_feature')
            ->and(stateOf(Feature::Customers))->toBe('on');
    });

    it('asks for a reason before reducing a feature that workspaces have been using', function () {
        $tenant = workspaceOn('pro');
        FeatureUsage::hit(Feature::ExportCsv, $tenant);
        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);

        foreach ([null, '', '  ', 'no'] as $reason) {
            expect(controlCode(fn () => control()->setState(Feature::ExportCsv, PlatformState::Off, $reason, null)))->toBe('reason_required');
        }
        expect(stateOf(Feature::ExportCsv))->toBe('on');

        control()->setState(Feature::ExportCsv, PlatformState::Beta, 'Fixing a bug in the file', null);
        expect(stateOf(Feature::ExportCsv))->toBe('beta')
            ->and(PlatformFeature::find('export_csv')->reason)->toBe('Fixing a bug in the file');
    });

    it('needs no reason to turn something on, to reduce something nobody used, or to undo', function () {
        control()->setState(Feature::CustomBranding, PlatformState::On, null, null);
        control()->setState(Feature::CustomBranding, PlatformState::Off, null, null);

        $tenant = workspaceOn('pro');
        FeatureUsage::hit(Feature::ExportCsv, $tenant);
        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);
        control()->setState(Feature::ExportCsv, PlatformState::Off, null, null, undo: true);

        expect(stateOf(Feature::ExportCsv))->toBe('off')
            ->and(AuditLog::where('action', 'feature.state_changed')->get()->contains(fn (AuditLog $row) => $row->changes['undo'] === true))->toBeTrue();
    });

    it('says which features depend on it, without blocking', function () {
        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);
        control()->setState(Feature::AdvancedReports, PlatformState::On, null, null);

        $dependents = control()->setState(Feature::ExportCsv, PlatformState::Off, null, null);

        expect($dependents)->toBe([Feature::AdvancedReports])
            ->and(stateOf(Feature::AdvancedReports))->toBe('on'); // it is not switched off: it stops because what it needs is gone
    });

    it('changes what a workspace gets at its very next request', function () {
        $tenant = workspaceOn('pro');
        expect(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeFalse();

        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);

        expect(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeTrue();
    });
});

describe('which plans include a feature', function () {
    it('writes the plan setting and changes the next answer for a workspace on that plan', function () {
        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);
        $free = workspaceOn('free');
        expect(Entitlements::for($free)->check(Feature::ExportCsv)->enabled())->toBeFalse();

        control()->setPlan(Feature::ExportCsv, Plan::where('key', 'free')->sole(), true, null, staffMember());

        expect(Entitlements::for($free)->check(Feature::ExportCsv)->enabled())->toBeTrue();
        expect(AuditLog::where('action', 'feature.plan_changed')->sole()->changes)->toMatchArray([
            'feature' => 'export_csv', 'plan' => 'free', 'before' => ['enabled' => false, 'limit' => null], 'after' => ['enabled' => true, 'limit' => null],
        ]);
    });

    it('sets a limit on a counted feature, and refuses a negative one or a limit on an on/off feature', function () {
        $free = Plan::where('key', 'free')->sole();

        control()->setPlan(Feature::Customers, $free, true, 12, null);
        expect(Entitlements::for(workspaceOn('free'))->check(Feature::Customers)->limit())->toBe(12);

        expect(controlCode(fn () => control()->setPlan(Feature::Customers, $free, true, -1, null)))->toBe('invalid_limit')
            ->and(controlCode(fn () => control()->setPlan(Feature::ExportCsv, $free, true, 5, null)))->toBe('invalid_limit');
    });
});

describe('letting one workspace in early', function () {
    it('grants a live override with a reason and an end date, and lets that workspace in while the rest wait', function () {
        $tenant = workspaceOn('free');
        $other = workspaceOn('free');
        $admin = staffMember();
        control()->setState(Feature::ExportCsv, PlatformState::Beta, null, null);

        $override = control()->grantBeta(Feature::ExportCsv, $tenant, 'Friendly shop, testing exports', now()->addDays(14), $admin);

        expect($override->enabled)->toBeTrue()->and($override->reason)->toBe('Friendly shop, testing exports')
            ->and($override->created_by_user_id)->toBe($admin->id)->and($override->expires_at->isFuture())->toBeTrue()
            ->and(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeTrue()
            ->and(Entitlements::for($other)->check(Feature::ExportCsv)->enabled())->toBeFalse();
        expect(AuditLog::where('action', 'feature.beta_granted')->sole()->changes)->toMatchArray(['feature' => 'export_csv', 'workspace' => $tenant->id]);
    });

    it('needs a reason, and takes the workspace out again when revoked', function () {
        $tenant = workspaceOn('free');
        control()->setState(Feature::ExportCsv, PlatformState::Beta, null, null);

        expect(controlCode(fn () => control()->grantBeta(Feature::ExportCsv, $tenant, '  ', null, null)))->toBe('reason_required');

        $override = control()->grantBeta(Feature::ExportCsv, $tenant, 'Pilot', null, null);
        control()->revokeBeta($override, staffMember());

        expect(TenantOverride::find($override->id))->toBeNull()
            ->and(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeFalse()
            ->and(AuditLog::where('action', 'feature.beta_revoked')->count())->toBe(1);
    });
});

describe('presets', function () {
    it('shows what would change without changing anything', function () {
        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);

        $diff = control()->previewPreset('full');

        expect(collect($diff)->pluck('feature')->sort()->values()->all())->toBe(['advanced_reports', 'custom_branding'])
            ->and($diff[0])->toHaveKeys(['feature', 'from', 'to'])
            ->and(stateOf(Feature::AdvancedReports))->toBe('off');
    });

    it('applies a preset to the switchable features only, with a reason, and audits it', function () {
        control()->applyPreset('full', 'Opening everything for the pilot', staffMember());

        expect(stateOf(Feature::AdvancedReports))->toBe('on')->and(stateOf(Feature::ExportCsv))->toBe('on')
            ->and(stateOf(Feature::CustomBranding))->toBe('on')->and(stateOf(Feature::Customers))->toBe('on');
        expect(AuditLog::where('action', 'feature.preset_applied')->sole()->changes)->toMatchArray(['preset' => 'full', 'reason' => 'Opening everything for the pilot']);

        expect(controlCode(fn () => control()->applyPreset('dark_launch', ' ', null)))->toBe('reason_required')
            ->and(controlCode(fn () => control()->applyPreset('nonsense', 'A fair reason', null)))->toBe('unknown_preset');
    });

    it('goes back to how things were before the last preset', function () {
        control()->setState(Feature::ExportCsv, PlatformState::Beta, null, null);

        control()->applyPreset('full', 'Everything on', null);
        expect(stateOf(Feature::ExportCsv))->toBe('on');

        control()->applyPreset('restore_previous', 'Back to before', null);
        expect(stateOf(Feature::ExportCsv))->toBe('beta')->and(stateOf(Feature::AdvancedReports))->toBe('off');
    });

    it('has nothing to restore before any preset', function () {
        expect(controlCode(fn () => control()->applyPreset('restore_previous', 'Please', null)))->toBe('no_snapshot');
    });

    it('turns on only the essentials, and darkens the rest', function () {
        control()->applyPreset('full', 'All', null);
        control()->applyPreset('essentials', 'Essentials only', null);

        expect(stateOf(Feature::CustomBranding))->toBe('on')->and(stateOf(Feature::ExportCsv))->toBe('off')->and(stateOf(Feature::AdvancedReports))->toBe('off');
    });

    it('pauses everything that touches customers, and nothing else', function () {
        control()->applyPreset('full', 'All', null);

        $paused = control()->pauseAutomation('Something is wrong with messages', staffMember());

        expect($paused)->toBe(1)->and(stateOf(Feature::ExportCsv))->toBe('off')->and(stateOf(Feature::AdvancedReports))->toBe('on');
    });
});

describe('the admin endpoints', function () {
    it('keep out guests, ordinary users and staff who have not set up a second factor', function () {
        $this->get('/admin/features')->assertRedirect('/login');

        [$owner] = owner();
        $this->actingAs($owner)->getJson('/admin/features')->assertNotFound();

        $this->actingAs(staffMember('super_admin', twoFactor: false))->get('/admin/features')->assertRedirect(route('security'));
    });

    it('let an admin look but not change, and a super admin do both', function () {
        $admin = staffMember('admin');

        $this->actingAs($admin)->getJson('/admin/features')->assertOk();
        $this->actingAs($admin)->putJson('/admin/features/export_csv/state', ['state' => 'on'])->assertForbidden();
        expect(stateOf(Feature::ExportCsv))->toBe('off');

        $this->actingAs(staffMember())->putJson('/admin/features/export_csv/state', ['state' => 'on'])->assertOk()->assertJsonPath('data.state', 'on');
        expect(stateOf(Feature::ExportCsv))->toBe('on');
    });

    it('describe every feature for the cockpit: state, plans, usage, relations, beta workspaces and history', function () {
        $super = staffMember();
        $tenant = workspaceOn('free');
        FeatureUsage::hit(Feature::ExportCsv, $tenant);
        control()->setState(Feature::ExportCsv, PlatformState::Beta, null, $super);
        control()->grantBeta(Feature::ExportCsv, $tenant, 'Pilot', null, $super);
        control()->setState(Feature::AdvancedReports, PlatformState::On, null, $super);

        $json = $this->actingAs($super)->getJson('/admin/features')->assertOk()->json();
        $cards = collect($json['groups'])->flatMap(fn ($g) => $g['features'])->keyBy('key');

        expect($json['summary'])->toBe(['on' => 5, 'beta' => 1, 'off' => 1 + count(array_filter(Feature::cases(), fn (Feature $f) => ! $f->isCore()))])
            ->and($cards->count())->toBe(count(Feature::cases()))
            ->and($cards['customers']['locked'])->toBeTrue()
            ->and($cards['export_csv'])->toMatchArray(['state' => 'beta', 'locked' => false, 'usage_30d' => 1, 'required_by' => ['advanced_reports'], 'depends_on' => []])
            ->and($cards['export_csv']['beta'][0])->toMatchArray(['reason' => 'Pilot', 'workspace' => ['id' => $tenant->id, 'name' => $tenant->name]])
            ->and($cards['export_csv']['history'][0])->toMatchArray(['from' => 'off', 'to' => 'beta'])
            ->and(collect($cards['export_csv']['plans'])->pluck('key')->all())->toContain('free', 'pro')
            ->and($cards['advanced_reports']['depends_on'])->toBe(['export_csv'])
            ->and($cards['advanced_reports']['dependency_problem'])->toBeTrue()
            ->and($cards['off_behaviour'] ?? $cards['export_csv']['off_behaviour'])->not->toBeEmpty();
    });

    it('answer with the reason when something is not allowed', function () {
        $super = staffMember();
        FeatureUsage::hit(Feature::ExportCsv, workspaceOn('pro'));
        control()->setState(Feature::ExportCsv, PlatformState::On, null, $super);

        $this->actingAs($super)->putJson('/admin/features/customers/state', ['state' => 'off', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'core_feature');
        $this->actingAs($super)->putJson('/admin/features/export_csv/state', ['state' => 'off'])
            ->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
        $this->actingAs($super)->putJson('/admin/features/export_csv/state', ['state' => 'sideways'])->assertStatus(422);
        $this->actingAs($super)->putJson('/admin/features/nothing/state', ['state' => 'on'])->assertNotFound();
    });

    it('change plans, grant beta by workspace id or by the owner’s e-mail, revoke it, and run presets', function () {
        $super = staffMember();
        [$owner, $tenant] = owner();

        $this->actingAs($super)->putJson('/admin/features/export_csv/plans/free', ['enabled' => true])->assertOk()->assertJsonPath('data.plans.0.key', 'free');
        expect(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeFalse(); // still dark at the platform level

        $byId = $this->actingAs($super)->postJson('/admin/features/export_csv/beta', ['workspace' => $tenant->id, 'reason' => 'Pilot'])->assertCreated();
        $this->actingAs($super)->postJson('/admin/features/export_csv/beta', ['workspace' => $owner->email, 'reason' => 'Again'])->assertCreated();
        $this->actingAs($super)->postJson('/admin/features/export_csv/beta', ['workspace' => 'nobody@example.com', 'reason' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_workspace');
        $this->actingAs($super)->deleteJson('/admin/features/export_csv/beta/'.$byId->json('data.id'))->assertOk();

        $this->actingAs($super)->getJson('/admin/features/presets/full/preview')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($super)->postJson('/admin/features/presets/full/apply', ['reason' => ''])->assertStatus(422)->assertJsonPath('error.code', 'reason_required');
        $this->actingAs($super)->postJson('/admin/features/presets/full/apply', ['reason' => 'Pilot week'])->assertOk();
        expect(stateOf(Feature::AdvancedReports))->toBe('on');

        $this->actingAs($super)->postJson('/admin/features/pause-automation', ['reason' => 'Stop all'])->assertOk()->assertJsonPath('paused', 1);
    });

    it('send a person using the page without JavaScript back to it with a message', function () {
        $super = staffMember();

        $this->actingAs($super)->from('/admin/features')->put('/admin/features/export_csv/state', ['state' => 'on'])
            ->assertRedirect('/admin/features')->assertSessionHas('status');
        $this->actingAs($super)->from('/admin/features')->put('/admin/features/customers/state', ['state' => 'off', 'reason' => 'x'])
            ->assertRedirect('/admin/features')->assertSessionHasErrors('state');
    });
});

describe('the command line', function () {
    it('lists every feature with its state and plans', function () {
        $this->artisan('qistas:features', ['action' => 'list'])->expectsOutputToContain('export_csv')->expectsOutputToContain('customers')->assertSuccessful();
    });

    it('switches a feature, audited as the command line', function () {
        $this->artisan('qistas:features', ['action' => 'state', 'feature' => 'export_csv', 'value' => 'beta'])->assertSuccessful();

        expect(stateOf(Feature::ExportCsv))->toBe('beta')
            ->and(AuditLog::where('action', 'feature.state_changed')->sole()->changes['actor'])->toBe('cli');
    });

    it('asks for a reason, as the screen does, when the feature is in use', function () {
        FeatureUsage::hit(Feature::ExportCsv, workspaceOn('pro'));
        control()->setState(Feature::ExportCsv, PlatformState::On, null, null);

        $this->artisan('qistas:features', ['action' => 'state', 'feature' => 'export_csv', 'value' => 'off'])->assertFailed();
        expect(stateOf(Feature::ExportCsv))->toBe('on');

        $this->artisan('qistas:features', ['action' => 'state', 'feature' => 'export_csv', 'value' => 'off', '--reason' => 'Fixing a bug'])->assertSuccessful();
        expect(stateOf(Feature::ExportCsv))->toBe('off');
    });

    it('sets a plan and syncs missing switches', function () {
        $this->artisan('qistas:features', ['action' => 'plan', 'feature' => 'customers', 'value' => 'free', 'extra' => 'on', '--limit' => '8'])->assertSuccessful();
        expect(Entitlements::for(workspaceOn('free'))->check(Feature::Customers)->limit())->toBe(8);

        PlatformFeature::query()->where('feature_key', 'export_csv')->delete();
        $this->artisan('qistas:features', ['action' => 'sync'])->assertSuccessful();
        expect(PlatformFeature::find('export_csv'))->not->toBeNull();
    });

    it('refuses what it does not understand', function () {
        $this->artisan('qistas:features', ['action' => 'state', 'feature' => 'nothing', 'value' => 'on'])->assertFailed();
        $this->artisan('qistas:features', ['action' => 'state', 'feature' => 'export_csv', 'value' => 'sideways'])->assertFailed();
        $this->artisan('qistas:features', ['action' => 'dance'])->assertFailed();
    });
});
