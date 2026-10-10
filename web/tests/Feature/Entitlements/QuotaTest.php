<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\LimitReached;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageCounter;

// About how a monthly allowance behaves, so it sets one; the free plan's real allowance is in FreePlanTest.
beforeEach(fn () => limitFreePlan(Feature::PdfStatements, 3));

function quotaTenant(string $plan = 'free'): Tenant
{
    $tenant = Tenant::factory()->create();
    $tenant->subscribeTo(Plan::where('key', $plan)->sole());

    return $tenant;
}

it('lets a Free workspace use its monthly allowance and then stops it', function () {
    $entitlements = Entitlements::for(quotaTenant());

    foreach (range(1, 3) as $_) {
        $entitlements->consume(Feature::PdfStatements);
    }

    $after = $entitlements->check(Feature::PdfStatements);
    expect($after->used())->toBe(3)->and($after->remaining())->toBe(0);
    expect(fn () => $entitlements->consume(Feature::PdfStatements))->toThrow(LimitReached::class);
    expect($entitlements->check(Feature::PdfStatements)->used())->toBe(3);
});

it('starts a new allowance on the first day of the next month', function () {
    $this->travelTo('2026-10-31 23:59:00');
    $entitlements = Entitlements::for(quotaTenant());
    foreach (range(1, 3) as $_) {
        $entitlements->consume(Feature::PdfStatements);
    }
    expect(fn () => $entitlements->consume(Feature::PdfStatements))->toThrow(LimitReached::class);

    $this->travelTo('2026-11-01 00:01:00');

    expect($entitlements->check(Feature::PdfStatements)->used())->toBe(0);
    $entitlements->consume(Feature::PdfStatements);
    expect($entitlements->check(Feature::PdfStatements)->used())->toBe(1);
});

it('keeps each workspace’s count separate', function () {
    $a = Entitlements::for(quotaTenant());
    $b = Entitlements::for(quotaTenant());

    $a->consume(Feature::PdfStatements, 3);

    expect($b->check(Feature::PdfStatements)->used())->toBe(0);
    $b->consume(Feature::PdfStatements);
});

it('refuses a request bigger than what is left, and takes nothing', function () {
    $entitlements = Entitlements::for(quotaTenant());
    $entitlements->consume(Feature::PdfStatements, 2);

    expect(fn () => $entitlements->consume(Feature::PdfStatements, 2))->toThrow(LimitReached::class);

    expect($entitlements->check(Feature::PdfStatements)->used())->toBe(2);
});

it('counts usage on an unlimited plan without ever refusing', function () {
    $entitlements = Entitlements::for(quotaTenant('pro'));

    $entitlements->consume(Feature::PdfStatements, 500);

    expect($entitlements->check(Feature::PdfStatements)->used())->toBe(500);
});

it('keeps one counter row per workspace, feature and month', function () {
    $tenant = quotaTenant();
    $entitlements = Entitlements::for($tenant);

    $entitlements->consume(Feature::PdfStatements);
    $entitlements->consume(Feature::PdfStatements);

    expect(UsageCounter::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('honours an override that raises the allowance', function () {
    $tenant = quotaTenant();
    $tenant->overrides()->create(['feature_key' => 'pdf_statements', 'enabled' => true, 'limit_value' => 10, 'reason' => 'Goodwill']);

    Entitlements::for($tenant)->consume(Feature::PdfStatements, 10);

    expect(Entitlements::for($tenant)->check(Feature::PdfStatements)->remaining())->toBe(0);
});

it('locks a quota the plan does not include', function () {
    Plan::where('key', 'free')->sole()->setFeature(Feature::PdfStatements, enabled: false);

    expect(fn () => Entitlements::for(quotaTenant())->consume(Feature::PdfStatements))->toThrow(FeatureLocked::class);
});

it('only counts features that are quotas', function (Feature $feature) {
    Entitlements::for(quotaTenant())->consume($feature);
})->with([Feature::Customers, Feature::ExportCsv])->throws(LogicException::class);

it('refuses a non-positive amount', function (int $amount) {
    Entitlements::for(quotaTenant())->consume(Feature::PdfStatements, $amount);
})->with([0, -1])->throws(InvalidArgumentException::class);
