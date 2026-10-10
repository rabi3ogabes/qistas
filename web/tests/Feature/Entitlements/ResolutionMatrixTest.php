<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureStatus;
use App\Entitlements\FeatureUnavailable;
use App\Entitlements\PlatformFeatures;
use App\Entitlements\PlatformState;
use App\Models\Plan;
use App\Models\PlatformFeature;
use App\Models\Tenant;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
| advanced_reports is the feature these tests drive: a plain on/off feature that Free does not have and Pro does,
| so "the plan includes it" is a choice of workspace. Its platform switch is set directly in the table, which is what
| the admin's cockpit does (through FeatureControl, Task 5).
*/

function switchPlatform(Feature $feature, string $state): void
{
    PlatformFeature::query()->where('feature_key', $feature->value)->update(['state' => $state]);
}

function overrideFor(Tenant $tenant, Feature $feature, bool $enabled, ?DateTimeInterface $expires = null): void
{
    $tenant->overrides()->create(['feature_key' => $feature->value, 'enabled' => $enabled, 'limit_value' => null, 'reason' => 'Test', 'expires_at' => $expires]);
}

function statusOf(Tenant $tenant, Feature $feature = Feature::AdvancedReports): string
{
    return Entitlements::for($tenant)->check($feature)->status()->value;
}

describe('the platform switch, the plan and the workspace override agree on one answer', function () {
    // Every combination, written out from the rules in the brief (3.1). Not computed: a table is the specification.
    it('resolves to the documented status', function (string $platform, bool $planIncludes, string $override, string $expected) {
        $tenant = workspaceOn($planIncludes ? 'pro' : 'free');
        switchPlatform(Feature::AdvancedReports, $platform);
        if ($override !== 'none') {
            overrideFor($tenant, Feature::AdvancedReports, $override === 'granted');
        }

        $entitlement = Entitlements::for($tenant)->check(Feature::AdvancedReports);

        expect($entitlement->status()->value)->toBe($expected)
            ->and($entitlement->enabled())->toBe($expected === 'on');
    })->with([
        'off, plan yes, no override' => ['off', true, 'none', 'platform_off'],
        'off, plan yes, granted' => ['off', true, 'granted', 'platform_off'],
        'off, plan yes, denied' => ['off', true, 'denied', 'platform_off'],
        'off, plan no, no override' => ['off', false, 'none', 'platform_off'],
        'off, plan no, granted' => ['off', false, 'granted', 'platform_off'],
        'off, plan no, denied' => ['off', false, 'denied', 'platform_off'],
        'beta, plan yes, no override' => ['beta', true, 'none', 'platform_off'],
        'beta, plan yes, granted' => ['beta', true, 'granted', 'on'],
        'beta, plan yes, denied' => ['beta', true, 'denied', 'platform_off'],
        'beta, plan no, no override' => ['beta', false, 'none', 'platform_off'],
        'beta, plan no, granted' => ['beta', false, 'granted', 'on'],
        'beta, plan no, denied' => ['beta', false, 'denied', 'platform_off'],
        'on, plan yes, no override' => ['on', true, 'none', 'on'],
        'on, plan yes, granted' => ['on', true, 'granted', 'on'],
        'on, plan yes, denied' => ['on', true, 'denied', 'plan_locked'],
        'on, plan no, no override' => ['on', false, 'none', 'plan_locked'],
        'on, plan no, granted' => ['on', false, 'granted', 'on'],
        'on, plan no, denied' => ['on', false, 'denied', 'plan_locked'],
    ]);

    it('gives a switched-off feature no allowance, whatever the plan says', function () {
        $tenant = workspaceOn('pro');
        switchPlatform(Feature::Customers, 'off');

        $entitlement = Entitlements::for($tenant)->check(Feature::Customers);

        expect($entitlement->status())->toBe(FeatureStatus::PlatformOff)
            ->and($entitlement->limit())->toBeNull()
            ->and($entitlement->remaining())->toBe(0)
            ->and($entitlement->allows())->toBeFalse();
    });
});

describe('deploying this changes nothing for anyone', function () {
    it('answers exactly as before when the platform table is empty', function (string $plan) {
        $tenant = workspaceOn($plan);
        $before = Entitlements::for($tenant)->toArray();

        PlatformFeature::query()->delete();
        $after = Entitlements::for($tenant)->toArray();

        // The features that worked before the switch system answer as they always did; new ones ship off.
        $core = collect($after['features'])->filter(fn (array $f, string $key) => Feature::from($key)->isCore());
        $new = collect($after['features'])->reject(fn (array $f, string $key) => Feature::from($key)->isCore());
        expect($after)->toEqual($before)
            ->and($core->every(fn (array $f) => $f['status'] === 'on' || $f['status'] === 'plan_locked'))->toBeTrue()
            ->and($new->every(fn (array $f) => $f['status'] === 'platform_off'))->toBeTrue();
        // Free still has its five customers and Pro its unlimited ones.
        expect($after['features']['customers']['limit'])->toBe($plan === 'free' ? Feature::FREE_CUSTOMERS : null);
    })->with(['free', 'pro']);

    it('treats every core feature as on, and every new one as off, until an admin says otherwise', function () {
        PlatformFeature::query()->delete();

        foreach (Feature::cases() as $feature) {
            expect(PlatformFeatures::state($feature))->toBe($feature->isCore() ? PlatformState::On : PlatformState::Off, $feature->value);
        }
    });
});

describe('beta only lets in a workspace with a live, enabled override', function () {
    it('stops letting a workspace in at the very moment its override expires', function () {
        $this->freezeTime();
        switchPlatform(Feature::AdvancedReports, 'beta');

        $expiring = workspaceOn('free');
        overrideFor($expiring, Feature::AdvancedReports, true, now());
        $lasting = workspaceOn('free');
        overrideFor($lasting, Feature::AdvancedReports, true, now()->addSecond());

        expect(statusOf($expiring))->toBe('platform_off')
            ->and(statusOf($lasting))->toBe('on');

        $this->travel(2)->seconds();
        expect(statusOf($lasting))->toBe('platform_off');
    });

    it('is per workspace: a grant to one never reaches another', function () {
        switchPlatform(Feature::AdvancedReports, 'beta');
        $invited = workspaceOn('free');
        $other = workspaceOn('free');
        overrideFor($invited, Feature::AdvancedReports, true);

        expect(statusOf($invited))->toBe('on')
            ->and(statusOf($other))->toBe('platform_off');
    });

    it('lets the newest override win when a workspace has two', function () {
        switchPlatform(Feature::AdvancedReports, 'beta');
        $tenant = workspaceOn('free');
        overrideFor($tenant, Feature::AdvancedReports, true);
        $this->travel(5)->seconds();
        overrideFor($tenant, Feature::AdvancedReports, false);

        expect(statusOf($tenant))->toBe('platform_off');
    });
});

describe('a feature that needs another one', function () {
    $everyone = fn (PlatformState $state) => array_fill_keys(array_map(fn (Feature $f) => $f->value, Feature::cases()), $state);
    $rows = fn (bool $dependencyIncluded) => [
        'advanced_reports' => ['enabled' => true, 'limit' => null],
        'export_csv' => ['enabled' => $dependencyIncluded, 'limit' => null],
    ];
    // The tests' own dependency graph over features that exist: reports need exports.
    $graph = fn (Feature $f): array => $f === Feature::AdvancedReports ? [Feature::ExportCsv] : [];
    $resolve = fn (array $platform, array $rows, array $overrides = []) => Entitlements::resolveStatus(Feature::AdvancedReports, $platform, $rows, $overrides, $graph);

    it('is switched off when what it needs is switched off, and says why', function () use ($everyone, $rows, $resolve) {
        $platform = [...$everyone(PlatformState::On), 'export_csv' => PlatformState::Off];

        expect($resolve($platform, $rows(true)))->toBe(['status' => FeatureStatus::PlatformOff, 'detail' => 'dependency:export_csv']);
    });

    it('is switched off when what it needs is in beta and this workspace is not in it', function () use ($everyone, $rows, $resolve) {
        $platform = [...$everyone(PlatformState::On), 'export_csv' => PlatformState::Beta];

        expect($resolve($platform, $rows(true)))->toBe(['status' => FeatureStatus::PlatformOff, 'detail' => 'dependency:export_csv'])
            ->and($resolve($platform, $rows(true), ['export_csv' => ['enabled' => true, 'limit' => null]])['status'])->toBe(FeatureStatus::On);
    });

    it('is plan-locked, with the reason, when the plan lacks what it needs', function () use ($everyone, $rows, $resolve) {
        expect($resolve($everyone(PlatformState::On), $rows(false)))->toBe(['status' => FeatureStatus::PlanLocked, 'detail' => 'dependency:export_csv']);
    });

    it('is on when what it needs is on and included', function () use ($everyone, $rows, $resolve) {
        expect($resolve($everyone(PlatformState::On), $rows(true)))->toBe(['status' => FeatureStatus::On, 'detail' => null]);
    });

    it('answers for itself first: its own switch off needs no explanation about dependencies', function () use ($everyone, $rows, $resolve) {
        $platform = [...$everyone(PlatformState::On), 'advanced_reports' => PlatformState::Off, 'export_csv' => PlatformState::Off];

        expect($resolve($platform, $rows(true)))->toBe(['status' => FeatureStatus::PlatformOff, 'detail' => null]);
    });
});

describe('two different refusals', function () {
    it('throws FeatureUnavailable when the platform has it off and FeatureLocked when only the plan lacks it', function () {
        $free = workspaceOn('free');
        expect(fn () => Entitlements::for($free)->assertEnabled(Feature::AdvancedReports))->toThrow(FeatureLocked::class);

        switchPlatform(Feature::AdvancedReports, 'off');
        expect(fn () => Entitlements::for($free)->assertEnabled(Feature::AdvancedReports))->toThrow(FeatureUnavailable::class)
            ->and(fn () => Entitlements::for(workspaceOn('pro'))->assertEnabled(Feature::AdvancedReports))->toThrow(FeatureUnavailable::class);
    });

    it('refuses to add or use a counted feature that is switched off, and consumes nothing', function () {
        $tenant = workspaceOn('pro');
        switchPlatform(Feature::Customers, 'off');
        switchPlatform(Feature::PdfStatements, 'off');

        expect(fn () => Entitlements::for($tenant)->assertCanCreate(Feature::Customers))->toThrow(FeatureUnavailable::class)
            ->and(fn () => Entitlements::for($tenant)->consume(Feature::PdfStatements))->toThrow(FeatureUnavailable::class)
            ->and(DB::table('usage_counters')->where('tenant_id', $tenant->id)->sum('used'))->toBe(0);
    });

    describe('over HTTP', function () {
        beforeEach(function () {
            // Outside api/v1 on purpose: a route made in a test outlives it, and the OpenAPI drift test reads every api/v1 route.
            Route::middleware(['api', 'auth:sanctum', 'account.active', 'tenant', 'feature:advanced_reports'])
                ->get('_probe/advanced-reports', fn () => response()->json(['ok' => true]));
        });

        it('answers 402 feature_locked, with a way to upgrade, when the plan lacks it', function () {
            [$user] = owner();
            Sanctum::actingAs($user, ['app']);

            $this->getJson('/_probe/advanced-reports')->assertStatus(402)
                ->assertJsonPath('error.code', 'feature_locked')
                ->assertJsonPath('error.feature', 'advanced_reports')
                ->assertJsonStructure(['error' => ['upgrade_url']]);
        });

        it('answers 403 feature_unavailable, with no way to upgrade, when the platform has it off', function () {
            [$user, $tenant] = owner();
            $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
            switchPlatform(Feature::AdvancedReports, 'off');
            Sanctum::actingAs($user, ['app']);

            $response = $this->getJson('/_probe/advanced-reports')->assertStatus(403)
                ->assertJsonPath('error.code', 'feature_unavailable')
                ->assertJsonPath('error.feature', 'advanced_reports');

            expect($response->json('error'))->not->toHaveKey('upgrade_url');
        });

        it('lets the request through when the feature is on for the workspace', function () {
            [$user, $tenant] = owner();
            $tenant->subscribeTo(Plan::where('key', 'pro')->sole());
            Sanctum::actingAs($user, ['app']);

            $this->getJson('/_probe/advanced-reports')->assertOk()->assertJson(['ok' => true]);
        });
    });
});

describe('what the clients read', function () {
    it('carries a status and a detail for every feature on /me', function () {
        [$user] = owner();
        Sanctum::actingAs($user, ['app']);
        switchPlatform(Feature::CustomBranding, 'off');

        $features = $this->getJson('/api/v1/me')->assertOk()->json('data.entitlements');

        expect($features)->toHaveCount(count(Feature::cases()));
        foreach ($features as $key => $feature) {
            expect($feature)->toHaveKeys(['status', 'detail', 'enabled'])
                ->and($feature['status'])->toBeIn(['on', 'plan_locked', 'platform_off'])
                ->and($feature['enabled'])->toBe($feature['status'] === 'on', $key);
        }
        expect($features['advanced_reports']['status'])->toBe('plan_locked')
            ->and($features['custom_branding']['status'])->toBe('platform_off')
            ->and($features['customers']['status'])->toBe('on');
    });

    it('shows a Blade section only for a feature that is on', function () {
        $template = "@feature('advanced_reports') SHOWN @endfeature";
        $pro = workspaceOn('pro');
        $free = workspaceOn('free');

        $shown = fn (Tenant $tenant) => trim(asTenant($tenant, fn () => Blade::render($template)));

        expect($shown($pro))->toBe('SHOWN')
            ->and($shown($free))->toBe('');

        switchPlatform(Feature::AdvancedReports, 'off');
        expect($shown($pro))->toBe('');
    });

    it('is quiet about features when nobody is signed in to a workspace', function () {
        expect(trim(Blade::render("@feature('advanced_reports') SHOWN @endfeature")))->toBe('');
    });
});
