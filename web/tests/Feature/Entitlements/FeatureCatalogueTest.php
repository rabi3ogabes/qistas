<?php

use App\Entitlements\Feature;
use App\Entitlements\FeatureGroup;
use App\Entitlements\PlatformFeatures;
use App\Entitlements\PlatformState;
use App\Models\PlatformFeature;

/** One dataset row per declared feature, named by its key. */
dataset('features', fn () => collect(Feature::cases())->mapWithKeys(fn (Feature $f) => [$f->value => [$f]])->all());

/** The seven features that worked before the switch system existed. They must keep working the day it ships. */
const CORE_FEATURES = ['customers', 'active_contracts', 'pdf_statements', 'export_csv', 'advanced_reports', 'custom_branding', 'api_tokens'];

describe('every feature declares its contract', function () {
    it('has a description and a plain sentence for what happens when it is switched off', function (Feature $feature) {
        expect($feature->description())->not->toBeEmpty()
            ->and($feature->offBehaviour())->not->toBeEmpty();
    })->with('features');

    it('has both sentences translated in every language', function (Feature $feature) {
        foreach (['ar', 'fr', 'es', 'ur'] as $language) {
            $table = json_decode(file_get_contents(lang_path("app/{$language}.json")), true, flags: JSON_THROW_ON_ERROR);

            foreach ([$feature->description(), $feature->offBehaviour()] as $sentence) {
                expect(array_key_exists($sentence, $table))->toBeTrue("{$language} is missing: {$sentence}")
                    ->and($table[$sentence])->not->toBeEmpty();
            }
        }
    })->with('features');

    it('belongs to a group, has a scope and a launch state', function (Feature $feature) {
        expect($feature->group())->toBeInstanceOf(FeatureGroup::class)
            ->and($feature->scope())->toBeIn(['workspace', 'platform'])
            ->and($feature->launchState())->toBeInstanceOf(PlatformState::class);
    })->with('features');

    it('never depends on itself, and the dependencies form no cycle', function () {
        $visit = function (Feature $feature, array $path) use (&$visit): void {
            expect($path)->not->toContain($feature->value, "cycle through {$feature->value}");

            foreach ($feature->dependsOn() as $dependency) {
                $visit($dependency, [...$path, $feature->value]);
            }
        };

        foreach (Feature::cases() as $feature) {
            $visit($feature, []);
        }
    });
});

describe('the features that already worked stay on', function () {
    it('is exactly the seven existing features that are core', function () {
        $core = collect(Feature::cases())->filter->isCore()->map->value->sort()->values()->all();

        expect($core)->toEqual(collect(CORE_FEATURES)->sort()->values()->all());
    });

    it('launches core features on, and in the workspace scope', function (string $key) {
        $feature = Feature::from($key);

        expect($feature->isCore())->toBeTrue()
            ->and($feature->group())->toBe(FeatureGroup::Core)
            ->and($feature->launchState())->toBe(PlatformState::On)
            ->and($feature->scope())->toBe('workspace');
    })->with(CORE_FEATURES);

    // The reader reports what is stored for every feature, core or not: the admin's cockpit and FeatureControl refuse
    // to change a core switch (Task 5), and only editing the table by hand can. Reading it honestly is what lets the
    // resolution and "off means off" be proven end to end with the features that exist today.
    it('reports the stored state, even for a core feature', function () {
        PlatformFeature::query()->where('feature_key', 'customers')->update(['state' => 'off']);

        expect(PlatformFeatures::state(Feature::Customers))->toBe(PlatformState::Off)
            ->and(PlatformFeatures::all()['customers'])->toBe(PlatformState::Off);
    });

    it('reports the launch state when the table has no rows at all', function (Feature $feature) {
        PlatformFeature::query()->delete();

        expect(PlatformFeatures::state($feature))->toBe($feature->launchState())
            ->and(PlatformFeatures::all()[$feature->value])->toBe($feature->launchState());
    })->with('features');
});

describe('where the platform state lives', function () {
    it('has a row for every case after migrating', function (Feature $feature) {
        $row = PlatformFeature::find($feature->value);

        expect($row)->not->toBeNull()
            ->and($row->state)->toBe($feature->launchState());
    })->with('features');

    it('inserts only what is missing, and says how many', function () {
        PlatformFeature::query()->delete();

        expect(PlatformFeatures::sync())->toBe(count(Feature::cases()))
            ->and(PlatformFeatures::sync())->toBe(0);
    });

    it('never overwrites a state an admin has set', function () {
        PlatformFeature::query()->where('feature_key', 'export_csv')->update(['state' => 'beta']);

        PlatformFeatures::sync();

        expect(PlatformFeature::find('export_csv')->state)->toBe(PlatformState::Beta);
    });

    it('is reachable as a command, which is what a deployment runs', function () {
        PlatformFeature::query()->delete();

        $this->artisan('qistas:sync-features')->expectsOutputToContain((string) count(Feature::cases()))->assertSuccessful();

        expect(PlatformFeature::count())->toBe(count(Feature::cases()));
    });
});
