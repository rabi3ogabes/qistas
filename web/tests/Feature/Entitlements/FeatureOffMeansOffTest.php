<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureGate;
use App\Models\Customer;
use App\Models\PlatformFeature;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProbeJob;

function flipPlatform(Feature $feature, string $state): void
{
    PlatformFeature::query()->where('feature_key', $feature->value)->update(['state' => $state]);
}

/** Run the next job on the real database queue, the way the cron endpoint's worker does. */
function workOnce(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();
}

beforeEach(fn () => ProbeJob::reset());

describe('work that was queued while a feature was on', function () {
    it('does nothing if the feature has been switched off by the time it runs, and says so', function () {
        $tenant = workspaceOn('pro');
        ProbeJob::dispatch($tenant->id)->onConnection('database');

        flipPlatform(Feature::AdvancedReports, 'off');
        Log::spy();
        workOnce();

        expect(ProbeJob::$ran)->toBe(0);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'skipped: feature_off'
            && $context['feature'] === 'advanced_reports'
            && $context['tenant'] === $tenant->id)->once();
    });

    it('runs again once the feature is back on, and sees the right workspace', function () {
        $tenant = workspaceOn('pro');
        flipPlatform(Feature::AdvancedReports, 'off');
        ProbeJob::dispatch($tenant->id)->onConnection('database');
        workOnce();
        expect(ProbeJob::$ran)->toBe(0);

        flipPlatform(Feature::AdvancedReports, 'on');
        ProbeJob::dispatch($tenant->id)->onConnection('database');
        workOnce();

        expect(ProbeJob::$ran)->toBe(1)->and(ProbeJob::$tenantSeen)->toBe($tenant->id);
    });

    it('is also dropped when the plan no longer includes the feature, or the workspace is gone', function () {
        $free = workspaceOn('free');
        ProbeJob::dispatch($free->id)->onConnection('database');
        workOnce();

        $gone = workspaceOn('pro');
        $goneId = $gone->id;
        ProbeJob::dispatch($goneId)->onConnection('database');
        $gone->delete();
        workOnce();

        expect(ProbeJob::$ran)->toBe(0);
    });

    it('leaves what was made while the feature was on exactly as it was', function () {
        $tenant = workspaceOn('pro');
        customerIn($tenant);
        $before = Entitlements::for($tenant)->check(Feature::Customers)->used();

        flipPlatform(Feature::Customers, 'off');
        expect(Entitlements::for($tenant)->check(Feature::Customers)->enabled())->toBeFalse()
            ->and(asTenant($tenant, fn () => Customer::count()))->toBe(1);

        flipPlatform(Feature::Customers, 'on');
        $after = Entitlements::for($tenant)->check(Feature::Customers);

        expect($after->enabled())->toBeTrue()->and($after->used())->toBe($before);
    });
});

describe('the gate that commands and actions use', function () {
    it('allows only when the feature is on for that workspace', function () {
        $pro = workspaceOn('pro');
        $free = workspaceOn('free');

        expect(FeatureGate::allows($pro, Feature::AdvancedReports))->toBeTrue()
            ->and(FeatureGate::allows($free, Feature::AdvancedReports))->toBeFalse();

        flipPlatform(Feature::AdvancedReports, 'off');
        expect(FeatureGate::allows($pro, Feature::AdvancedReports))->toBeFalse();
    });

    it('logs why a piece of work was skipped, with no personal data', function () {
        $free = workspaceOn('free');
        Log::spy();

        expect(FeatureGate::passes($free, Feature::AdvancedReports, 'a test'))->toBeFalse();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'skipped: feature_off'
            && array_keys($context) === ['feature', 'tenant', 'by'])->once();
    });
});
