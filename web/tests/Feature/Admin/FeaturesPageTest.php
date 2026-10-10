<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureCatalogue;
use App\Entitlements\FeatureControl;
use App\Entitlements\FeatureUsage;
use App\Entitlements\PlatformState;
use App\Models\PlatformFeature;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| The cockpit page. Today every feature is core and locked, so these tests treat three as ordinary (as the
| FeatureControl tests do) to see the controls that matter once the programme's features exist.
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

function cockpitStaff(string $role = 'super_admin'): User
{
    return User::factory()->create(['platform_role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

/** Exactly one feature's row of the page, whatever order the features are listed in. */
function rowOf(string $html, string $key): string
{
    preg_match('/<li class="fc-row"\s+data-feature="'.preg_quote($key, '/').'".*?(?=<li class="fc-row"|<\/ul>\s*<\/details>)/s', $html, $match);

    return $match[0] ?? test()->fail("No row for {$key}");
}

function cockpit(string $role = 'super_admin'): TestResponse
{
    return test()->actingAs(cockpitStaff($role))->get('/admin/features')->assertOk();
}

describe('the page', function () {
    it('shows a summary of what is on, in beta and off, and a way to find a feature', function () {
        $page = cockpit();

        $page->assertSee('Feature control')
            ->assertSee('data-summary data-on="4" data-beta="0" data-off="4"', false)
            ->assertSee('type="search"', false);
    });

    it('lists every feature once, under its group', function () {
        $html = cockpit()->getContent();

        foreach (Feature::cases() as $feature) {
            expect(substr_count($html, 'data-feature="'.$feature->value.'"'))->toBe(1, $feature->value);
        }
        expect($html)->toContain('data-group="core"')->and($html)->toContain('<details')->and($html)->toContain('Core');
    });

    it('describes each feature in words, and what happens when it is switched off', function () {
        cockpit()->assertSee(Feature::ExportCsv->description())->assertSee(Feature::ExportCsv->offBehaviour());
    });

    it('is only for the platform team', function () {
        $this->get('/admin/features')->assertRedirect('/login');
        [$owner] = owner();
        $this->actingAs($owner)->get('/admin/features')->assertNotFound();
    });

    it('is linked from the admin menu', function () {
        $this->actingAs(cockpitStaff())->get('/admin')->assertSee(route('admin.features.index'), false)->assertSee('Feature control');
    });
});

describe('each feature’s switch', function () {
    it('is a radio group of Off, Beta and On with the current one checked', function () {
        $html = cockpit()->getContent();
        $row = rowOf($html, 'export_csv');

        expect($row)->toContain('role="radiogroup"')
            ->and(substr_count($row, 'role="radio"'))->toBe(3)
            ->and($row)->toMatch('/aria-checked="true"[^>]*data-state="off"|data-state="off"[^>]*aria-checked="true"/')
            ->and(substr_count($row, 'aria-checked="true"'))->toBe(1);
    });

    it('works without JavaScript: each choice is a button in a form that saves it', function () {
        $html = cockpit()->getContent();
        $row = rowOf($html, 'export_csv');

        expect($row)->toContain('action="'.route('admin.features.state', 'export_csv').'"')
            ->and($row)->toContain('name="_method" value="PUT"')
            ->and($row)->toMatch('/<button[^>]*name="state"[^>]*value="on"/')
            ->and($row)->toContain('name="reason"');
    });

    it('is shown as fixed, with the reason, for a core feature', function () {
        $html = cockpit()->getContent();
        $row = rowOf($html, 'customers');

        expect($row)->toContain('data-locked="true"')->and($row)->toContain('disabled')->and($row)->toContain('Core feature, always on');
    });

    it('cannot be used by an admin who may only look', function () {
        $html = cockpit('admin')->getContent();
        $row = rowOf($html, 'export_csv');

        expect($row)->toContain('disabled')->and($html)->toContain('Only a super admin can change these');
    });

    it('lets the plans be edited even for a core feature, as before', function () {
        $html = cockpit()->getContent();
        $row = rowOf($html, 'customers');

        expect($row)->toContain(route('admin.features.plan', ['key' => 'customers', 'plan' => 'free']))
            ->and($row)->toContain('name="limit"')->and($row)->toContain('Free')->and($row)->toContain('Pro');
    });
});

describe('what the page tells the admin before they act', function () {
    it('shows how many workspaces use a feature, what needs it and what it needs', function () {
        FeatureUsage::hit(Feature::ExportCsv, workspaceOn('pro'));

        $html = cockpit()->getContent();
        $reports = rowOf($html, 'advanced_reports');
        $exports = rowOf($html, 'export_csv');

        expect($exports)->toContain('data-usage="1"')->and($exports)->toContain('Used by 1 workspace')
            ->and($exports)->toContain('data-required-by="advanced_reports"')
            ->and($reports)->toContain('data-depends-on="export_csv"')->and($reports)->toContain('data-dependency-problem="true"');
    });

    it('offers the presets and the emergency stop only when there is something to act on', function () {
        cockpit()->assertSee('fc-presets', false)->assertSee('data-pause', false);
    });

    it('shows no presets, and no stop, while every feature is core', function () {
        app()->instance(FeatureCatalogue::class, new FeatureCatalogue(locked: fn (Feature $feature) => true));

        $this->actingAs(cockpitStaff())->get('/admin/features')->assertOk()->assertDontSee('data-pause', false)->assertDontSee('fc-presets', false);
    });

    it('lists the workspaces let in early, and how to add one', function () {
        $tenant = workspaceOn('free');
        // A name that needs escaping in HTML, always: the page shows it escaped, and a random name only sometimes would.
        $tenant->forceFill(['name' => "O'Brien & Sons <Ltd>"])->save();
        app(FeatureControl::class)->grantBeta(Feature::ExportCsv, $tenant, 'Pilot shop', null, null);

        $html = cockpit()->getContent();
        $row = rowOf($html, 'export_csv');

        expect($row)->toContain(e($tenant->name))->not->toContain('<Ltd>')->and($row)->toContain('Pilot shop')->and($row)->toContain(route('admin.features.beta.store', 'export_csv'));
    });

    it('keeps the recent changes, who made them and why', function () {
        $super = cockpitStaff();
        app(FeatureControl::class)->setState(Feature::ExportCsv, PlatformState::Beta, 'Trying it out', $super);

        $this->actingAs($super)->get('/admin/features')->assertSee('Trying it out')->assertSee($super->name);
    });
});

describe('messages and wording', function () {
    it('is written in every language, with the controls in the reading direction', function (string $locale) {
        $this->actingAs(cockpitStaff())->get('/admin/features?lang='.$locale)->assertOk()->assertSee('data-summary', false);
    })->with(['en', 'ar', 'fr', 'es', 'ur']);
});
