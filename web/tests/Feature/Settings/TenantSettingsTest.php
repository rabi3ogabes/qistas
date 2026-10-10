<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlatformFeature;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\TenantSettings;
use Illuminate\Validation\ValidationException;

/*
| No real feature has settings yet, so these tests register their own for `advanced_reports` (a Pro-only on/off
| feature). The registry belongs to the application instance, which every test gets afresh.
*/

beforeEach(function () {
    $registry = app(SettingsRegistry::class);
    $registry->register(new SettingDefinition(
        key: 'reports.window_days', feature: Feature::AdvancedReports, type: 'int', default: 30,
        rules: ['min:1', 'max:90'], label: 'Days a report looks back', help: 'Between 1 and 90.',
    ));
    $registry->register(new SettingDefinition(
        key: 'reports.weekly_email', feature: Feature::AdvancedReports, type: 'switch', default: false,
        rules: [], label: 'Send the weekly summary',
    ));
    $registry->register(new SettingDefinition(
        key: 'reports.first_day', feature: Feature::AdvancedReports, type: 'select', default: 'sat',
        rules: [], label: 'The week starts on', options: ['sat' => 'Saturday', 'sun' => 'Sunday', 'mon' => 'Monday'],
    ));
});

function proWorkspace(): array
{
    [$user, $tenant] = owner();
    $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

    return [$user, $tenant];
}

describe('what a workspace can set', function () {
    it('lists only the settings of features that are on for it', function () {
        $pro = workspaceOn('pro');
        $free = workspaceOn('free');
        $registry = app(SettingsRegistry::class);

        expect(array_map(fn (SettingDefinition $d) => $d->key, $registry->definitionsFor($pro)))->toBe(['reports.window_days', 'reports.weekly_email', 'reports.first_day'])
            ->and($registry->definitionsFor($free))->toBe([]);

        PlatformFeature::query()->where('feature_key', 'advanced_reports')->update(['state' => 'off']);
        expect($registry->definitionsFor($pro))->toBe([]);
    });

    it('gives the default until something is set, with the right type', function () {
        $settings = TenantSettings::for(workspaceOn('pro'));

        expect($settings->get('reports.window_days'))->toBe(30)
            ->and($settings->get('reports.weekly_email'))->toBeFalse()
            ->and($settings->get('reports.first_day'))->toBe('sat');
    });

    it('stores a valid value in its own type and reads it back', function () {
        $settings = TenantSettings::for(workspaceOn('pro'));

        $settings->set('reports.window_days', '45');
        $settings->set('reports.weekly_email', 1);
        $settings->set('reports.first_day', 'sun');

        expect($settings->get('reports.window_days'))->toBe(45)
            ->and($settings->get('reports.weekly_email'))->toBeTrue()
            ->and($settings->get('reports.first_day'))->toBe('sun');
    });

    it('refuses a value outside the rules, and keeps what was there', function () {
        $settings = TenantSettings::for(workspaceOn('pro'));
        $settings->set('reports.window_days', 45);

        foreach ([['reports.window_days', 0], ['reports.window_days', 91], ['reports.window_days', 'many'], ['reports.first_day', 'tue'], ['reports.weekly_email', 'maybe']] as [$key, $value]) {
            expect(fn () => $settings->set($key, $value))->toThrow(ValidationException::class);
        }

        expect($settings->get('reports.window_days'))->toBe(45);
    });

    it('does not know a setting nobody declared', function () {
        expect(fn () => TenantSettings::for(workspaceOn('pro'))->get('nothing.here'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => TenantSettings::for(workspaceOn('pro'))->set('nothing.here', 1))->toThrow(InvalidArgumentException::class);
    });

    it('is private to each workspace', function () {
        $a = TenantSettings::for(workspaceOn('pro'));
        $b = TenantSettings::for(workspaceOn('pro'));

        $a->set('reports.window_days', 60);

        expect($a->get('reports.window_days'))->toBe(60)->and($b->get('reports.window_days'))->toBe(30);
    });

    it('writes an audit row with what it was and what it became, and nothing when nothing changed', function () {
        [$user, $tenant] = proWorkspace();
        $settings = TenantSettings::for($tenant);

        $settings->set('reports.window_days', 45, $user);
        $settings->set('reports.window_days', 45, $user);

        $rows = AuditLog::where('action', 'settings.changed')->get();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->tenant_id)->toBe($tenant->id)
            ->and($rows->first()->user_id)->toBe($user->id)
            ->and($rows->first()->changes)->toBe(['key' => 'reports.window_days', 'before' => 30, 'after' => 45]);
    });

    it('is refused for a feature that is switched off, or that the plan lacks, and keeps the stored value', function () {
        $pro = workspaceOn('pro');
        $settings = TenantSettings::for($pro);
        $settings->set('reports.window_days', 45);

        PlatformFeature::query()->where('feature_key', 'advanced_reports')->update(['state' => 'off']);
        expect(fn () => $settings->set('reports.window_days', 60))->toThrow(FeatureUnavailable::class)
            ->and($settings->get('reports.window_days'))->toBe(45);

        // With the platform switch back on, only the plan is what says no.
        PlatformFeature::query()->where('feature_key', 'advanced_reports')->update(['state' => 'on']);
        expect(fn () => TenantSettings::for(workspaceOn('free'))->set('reports.window_days', 60))->toThrow(FeatureLocked::class);
    });
});

describe('over the API', function () {
    it('lists the settings with their values, their kind and who may change them', function () {
        [, $tenant] = apiOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        TenantSettings::for($tenant)->set('reports.window_days', 45);

        $response = $this->getJson('/api/v1/settings/tools')->assertOk();

        expect($response->json('meta.can_edit'))->toBeTrue()
            ->and($response->json('data.0'))->toMatchArray(['key' => 'reports.window_days', 'feature' => 'advanced_reports', 'type' => 'int', 'label' => 'Days a report looks back', 'help' => 'Between 1 and 90.', 'value' => 45])
            ->and($response->json('data.1'))->toMatchArray(['type' => 'switch', 'value' => false])
            ->and($response->json('data.2.options'))->toBe([['value' => 'sat', 'label' => 'Saturday'], ['value' => 'sun', 'label' => 'Sunday'], ['value' => 'mon', 'label' => 'Monday']]);
    });

    it('is empty when nothing is on for the workspace', function () {
        apiOwner();

        $this->getJson('/api/v1/settings/tools')->assertOk()->assertExactJson(['data' => [], 'meta' => ['can_edit' => true]]);
    });

    it('saves a value and answers with the setting as it now is', function () {
        [, $tenant] = apiOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

        $this->putJson('/api/v1/settings/tools/reports.window_days', ['value' => 45])
            ->assertOk()->assertJsonPath('data.value', 45)->assertJsonPath('data.key', 'reports.window_days');

        expect(TenantSettings::for($tenant)->get('reports.window_days'))->toBe(45);
    });

    it('answers 422 with the field for a value that is not allowed', function () {
        [, $tenant] = apiOwner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

        $this->putJson('/api/v1/settings/tools/reports.window_days', ['value' => 500])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['value']]]);
    });

    it('answers 404 for a setting nobody declared', function () {
        apiOwner();

        $this->putJson('/api/v1/settings/tools/nothing.here', ['value' => 1])->assertNotFound();
    });

    it('answers 403 feature_unavailable when the platform has the feature off, and 402 when only the plan lacks it', function () {
        [, $free] = apiOwner();
        $this->putJson('/api/v1/settings/tools/reports.window_days', ['value' => 45])->assertStatus(402)->assertJsonPath('error.code', 'feature_locked');

        $free->subscribeTo(Plan::where('key', 'pro')->sole());
        PlatformFeature::query()->where('feature_key', 'advanced_reports')->update(['state' => 'off']);
        $this->putJson('/api/v1/settings/tools/reports.window_days', ['value' => 45])->assertStatus(403)->assertJsonPath('error.code', 'feature_unavailable');
    });

    it('lets an owner and a manager change settings, and nobody else', function (string $role, int $status) {
        [, $tenant] = owner();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
        apiMember($role, $tenant);

        $this->putJson('/api/v1/settings/tools/reports.window_days', ['value' => 45])->assertStatus($status);
        // Everyone in the workspace may look.
        $this->getJson('/api/v1/settings/tools')->assertOk()->assertJsonPath('meta.can_edit', $status === 200);
    })->with([['owner', 200], ['manager', 200], ['accountant', 403], ['collector', 403], ['viewer', 403]]);
});

describe('on the web', function () {
    it('says plainly when there is nothing to set', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/settings/tools')->assertOk()
            ->assertSee('No instalment tools are available yet.');
    });

    it('shows each setting with its control and its value', function () {
        [$user, $tenant] = proWorkspace();
        TenantSettings::for($tenant)->set('reports.window_days', 45);

        $this->actingAs($user)->get('/app/settings/tools')->assertOk()
            ->assertSee('Days a report looks back')
            ->assertSee('value="45"', false)
            ->assertSee('Send the weekly summary')
            ->assertSee('Saturday')
            ->assertDontSee('No instalment tools are available yet.');
    });

    it('saves a setting and comes back to the page', function () {
        [$user, $tenant] = proWorkspace();

        $this->actingAs($user)->put('/app/settings/tools/reports.window_days', ['value' => 60])
            ->assertRedirect('/app/settings/tools')->assertSessionHas('status');

        expect(TenantSettings::for($tenant)->get('reports.window_days'))->toBe(60);
    });

    it('sends someone back with the reason when the value is not allowed', function () {
        [$user, $tenant] = proWorkspace();

        $this->actingAs($user)->from('/app/settings/tools')->put('/app/settings/tools/reports.window_days', ['value' => 500])
            ->assertRedirect('/app/settings/tools')->assertSessionHasErrors('value', null, 'tool-reports.window_days');

        expect(TenantSettings::for($tenant)->get('reports.window_days'))->toBe(30);
    });

    it('does not let a viewer change anything', function () {
        [, $tenant] = proWorkspace();
        $viewer = memberAs('viewer', $tenant);

        $this->actingAs($viewer)->put('/app/settings/tools/reports.window_days', ['value' => 60])->assertForbidden();
        $this->actingAs($viewer)->get('/app/settings/tools')->assertOk()->assertSee('Days a report looks back');
    });

    it('is always linked from the menu, as the instalment tools when there are some and as settings otherwise', function () {
        [$withTools] = proWorkspace();
        [$without] = owner();

        // The page also holds the business's phone-security rule, so it is always reachable.
        $this->actingAs($withTools)->get('/app')->assertSee(route('app.settings.tools'), false)->assertSee('Instalment tools');
        $this->actingAs($without)->get('/app')->assertSee(route('app.settings.tools'), false)->assertDontSee('Instalment tools');
    });
});
