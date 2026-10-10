<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureType;
use App\Entitlements\LimitReached;
use App\Entitlements\UsageMeters;
use App\Models\Plan;
use App\Models\Tenant;

/** A workspace subscribed to the plan with this key (free and pro exist after migration). */
function tenantOn(string $plan = 'free'): Tenant
{
    $tenant = Tenant::factory()->create();
    $tenant->subscribeTo(Plan::where('key', $plan)->sole());

    return $tenant;
}

/** Pretend the workspace already uses this many of a counted feature (the real meters arrive with their modules). */
function usage(Feature $feature, int $used): void
{
    app(UsageMeters::class)->register($feature, fn () => $used);
}

beforeEach(function () {
    foreach (Feature::cases() as $feature) {
        if ($feature->type() === FeatureType::Limit) {
            usage($feature, 0);
        }
    }
});

describe('the default Free and Pro values', function () {
    it('gives a Free workspace the documented allowance', function (Feature $feature, bool $enabled, ?int $limit) {
        $entitlement = Entitlements::for(tenantOn('free'))->check($feature);

        expect($entitlement->enabled())->toBe($enabled)->and($entitlement->limit())->toBe($limit);
    })->with([
        'customers' => [Feature::Customers, true, 20],
        'active contracts' => [Feature::ActiveContracts, true, null],
        'pdf statements' => [Feature::PdfStatements, true, 5],
        'csv export' => [Feature::ExportCsv, false, null],
        'advanced reports' => [Feature::AdvancedReports, false, null],
        'custom branding' => [Feature::CustomBranding, false, null],
        'api tokens' => [Feature::ApiTokens, true, 1],
    ]);

    it('gives a Pro workspace every core feature, unlimited', function (Feature $feature) {
        $entitlement = Entitlements::for(tenantOn('pro'))->check($feature);

        expect($entitlement->enabled())->toBeTrue()->and($entitlement->limit())->toBeNull();
    })->with(fn () => array_values(array_filter(Feature::cases(), fn (Feature $f) => $f->isCore())));

    it('gives Pro three people, and Free one, once the team is switched on', function () {
        switchOn(Feature::Members);

        expect(Entitlements::for(tenantOn('pro'))->check(Feature::Members)->limit())->toBe(3)
            ->and(Entitlements::for(tenantOn('free'))->check(Feature::Members)->limit())->toBe(1);
    });

    it('treats a workspace with no subscription as Free', function () {
        $tenant = Tenant::factory()->create();

        expect(Entitlements::for($tenant)->check(Feature::Customers)->limit())->toBe(Feature::FREE_CUSTOMERS);
    });

    it('covers every feature in the code with a type and a label', function (Feature $feature) {
        expect($feature->type())->toBeInstanceOf(FeatureType::class)->and($feature->label())->not->toBe('');
    })->with(fn () => Feature::cases());
});

describe('reading an entitlement', function () {
    it('reports used, remaining and unlimited', function () {
        usage(Feature::Customers, 3);

        $free = Entitlements::for(tenantOn('free'))->check(Feature::Customers);
        $pro = Entitlements::for(tenantOn('pro'))->check(Feature::Customers);

        expect($free->used())->toBe(3)->and($free->remaining())->toBe(Feature::FREE_CUSTOMERS - 3)->and($free->unlimited())->toBeFalse()
            ->and($pro->used())->toBe(3)->and($pro->remaining())->toBeNull()->and($pro->unlimited())->toBeTrue();
    });

    it('never reports a negative remainder when the plan was downgraded below current usage', function () {
        usage(Feature::Customers, 40);

        expect(Entitlements::for(tenantOn('free'))->check(Feature::Customers)->remaining())->toBe(0);
    });

    it('fails loudly if a counted feature has no meter, rather than letting everyone through', function () {
        $this->app->forgetInstance(UsageMeters::class);

        Entitlements::for(tenantOn('free'))->check(Feature::Customers);
    })->throws(LogicException::class, 'customers');
});

describe('creating things', function () {
    // About how a limit behaves, so it sets one rather than relying on the free plan's built-in allowance.
    beforeEach(fn () => limitFreePlan(Feature::Customers, 5));

    it('allows creation below the limit', function () {
        usage(Feature::Customers, 4);

        Entitlements::for(tenantOn('free'))->assertCanCreate(Feature::Customers);

        expect(true)->toBeTrue();
    });

    it('blocks creation at the limit and says what was reached', function () {
        usage(Feature::Customers, 5);

        try {
            Entitlements::for(tenantOn('free'))->assertCanCreate(Feature::Customers);
            $this->fail('Expected LimitReached');
        } catch (LimitReached $e) {
            expect($e->feature)->toBe(Feature::Customers)->and($e->limit)->toBe(5)->and($e->used)->toBe(5);
        }
    });

    it('blocks a batch that would cross the limit', function () {
        usage(Feature::Customers, 3);

        expect(fn () => Entitlements::for(tenantOn('free'))->assertCanCreate(Feature::Customers, 3))
            ->toThrow(LimitReached::class);
    });

    it('never blocks an unlimited plan', function () {
        usage(Feature::Customers, 10_000);

        Entitlements::for(tenantOn('pro'))->assertCanCreate(Feature::Customers);

        expect(true)->toBeTrue();
    });

    it('locks a feature that the plan does not include', function () {
        expect(fn () => Entitlements::for(tenantOn('free'))->assertCanCreate(Feature::ExportCsv))
            ->toThrow(FeatureLocked::class);
    });

    it('keeps existing data and only blocks new records after a downgrade from Pro', function () {
        $tenant = tenantOn('pro');
        usage(Feature::Customers, 40);
        Entitlements::for($tenant)->assertCanCreate(Feature::Customers);

        $tenant->subscribeTo(Plan::where('key', 'free')->sole());

        $entitlement = Entitlements::for($tenant->fresh())->check(Feature::Customers);
        expect($entitlement->used())->toBe(40)->and($entitlement->remaining())->toBe(0);
        expect(fn () => Entitlements::for($tenant->fresh())->assertCanCreate(Feature::Customers))->toThrow(LimitReached::class);
    });
});

describe('the admin matrix', function () {
    it('shows an edit on the very next check, with no stale cache', function () {
        $tenant = tenantOn('free');
        $entitlements = Entitlements::for($tenant);
        expect($entitlements->check(Feature::Customers)->limit())->toBe(Feature::FREE_CUSTOMERS);

        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 30);
        expect($entitlements->check(Feature::Customers)->limit())->toBe(30);

        Plan::where('key', 'free')->sole()->setFeature(Feature::ExportCsv, enabled: true);
        expect($entitlements->check(Feature::ExportCsv)->enabled())->toBeTrue();
    });

    it('lets the admin switch a feature off for Pro', function () {
        Plan::where('key', 'pro')->sole()->setFeature(Feature::CustomBranding, enabled: false);

        expect(Entitlements::for(tenantOn('pro'))->check(Feature::CustomBranding)->enabled())->toBeFalse()
            ->and(Entitlements::for(tenantOn('pro'))->check(Feature::Customers)->enabled())->toBeTrue();
    });

    it('treats a plan the admin created as having nothing until features are given', function () {
        $plan = Plan::create(['name' => 'Studio', 'description' => null, 'sort_order' => 30, 'is_public' => true]);
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo($plan);

        expect(Entitlements::for($tenant)->check(Feature::Customers)->enabled())->toBeFalse();

        $plan->setFeature(Feature::Customers, enabled: true, limit: 100);
        expect(Entitlements::for($tenant)->check(Feature::Customers)->limit())->toBe(100);
    });

    it('rejects values that make no sense', function (Feature $feature, bool $enabled, ?int $limit) {
        Plan::where('key', 'free')->sole()->setFeature($feature, $enabled, $limit);
    })->with([
        'negative limit' => [Feature::Customers, true, -1],
        'a limit on an on/off feature' => [Feature::ExportCsv, true, 10],
    ])->throws(InvalidArgumentException::class);
});

describe('per-workspace overrides', function () {
    it('beats the plan, in both directions', function () {
        $free = tenantOn('free');
        $free->overrides()->create(['feature_key' => 'export_csv', 'enabled' => true, 'limit_value' => null, 'reason' => 'Pilot customer']);
        $free->overrides()->create(['feature_key' => 'customers', 'enabled' => true, 'limit_value' => 50, 'reason' => 'Goodwill']);
        $pro = tenantOn('pro');
        $pro->overrides()->create(['feature_key' => 'custom_branding', 'enabled' => false, 'limit_value' => null, 'reason' => 'Abuse']);

        expect(Entitlements::for($free)->check(Feature::ExportCsv)->enabled())->toBeTrue()
            ->and(Entitlements::for($free)->check(Feature::Customers)->limit())->toBe(50)
            ->and(Entitlements::for($pro)->check(Feature::CustomBranding)->enabled())->toBeFalse();
    });

    it('beats a setting the admin made on the plan itself', function () {
        $free = Plan::where('key', 'free')->sole();
        $pro = Plan::where('key', 'pro')->sole();
        $free->setFeature(Feature::Customers, enabled: true, limit: 10);
        $free->setFeature(Feature::ExportCsv, enabled: false);
        $pro->setFeature(Feature::CustomBranding, enabled: true);

        $onFree = tenantOn('free');
        $onFree->overrides()->create(['feature_key' => 'customers', 'enabled' => true, 'limit_value' => 25, 'reason' => 'Goodwill']);
        $onFree->overrides()->create(['feature_key' => 'export_csv', 'enabled' => true, 'limit_value' => null, 'reason' => 'Pilot']);
        $onPro = tenantOn('pro');
        $onPro->overrides()->create(['feature_key' => 'custom_branding', 'enabled' => false, 'limit_value' => null, 'reason' => 'Abuse']);

        expect(Entitlements::for($onFree)->check(Feature::Customers)->limit())->toBe(25)
            ->and(Entitlements::for($onFree)->check(Feature::ExportCsv)->enabled())->toBeTrue()
            ->and(Entitlements::for($onPro)->check(Feature::CustomBranding)->enabled())->toBeFalse();
    });

    it('can lift a limit entirely', function () {
        $tenant = tenantOn('free');
        $tenant->overrides()->create(['feature_key' => 'customers', 'enabled' => true, 'limit_value' => null, 'reason' => 'Unlimited trial']);

        expect(Entitlements::for($tenant)->check(Feature::Customers)->unlimited())->toBeTrue();
    });

    it('only affects its own workspace', function () {
        $a = tenantOn('free');
        $b = tenantOn('free');
        $a->overrides()->create(['feature_key' => 'export_csv', 'enabled' => true, 'limit_value' => null, 'reason' => 'x']);

        expect(Entitlements::for($b)->check(Feature::ExportCsv)->enabled())->toBeFalse();
    });

    it('expires on its own', function () {
        $this->travelTo('2026-10-07 12:00:00');
        $tenant = tenantOn('free');
        $tenant->overrides()->create([
            'feature_key' => 'export_csv', 'enabled' => true, 'limit_value' => null,
            'reason' => 'Two-week trial', 'expires_at' => now()->addDays(14),
        ]);

        $this->travelTo('2026-10-20 12:00:00');
        expect(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeTrue();

        $this->travelTo('2026-10-21 12:00:01');
        expect(Entitlements::for($tenant)->check(Feature::ExportCsv)->enabled())->toBeFalse();
    });

    it('uses the newest when several are active', function () {
        $tenant = tenantOn('free');
        $tenant->overrides()->create(['feature_key' => 'customers', 'enabled' => true, 'limit_value' => 20, 'reason' => 'older']);
        $this->travel(1)->minutes();
        $tenant->overrides()->create(['feature_key' => 'customers', 'enabled' => true, 'limit_value' => 30, 'reason' => 'newer']);

        expect(Entitlements::for($tenant)->check(Feature::Customers)->limit())->toBe(30);
    });
});

describe('which plan applies', function () {
    it('applies the paid plan while a subscription is current', function (string $status) {
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), status: $status, periodEnd: now()->addDays(10));

        expect(Entitlements::for($tenant)->check(Feature::Customers)->unlimited())->toBeTrue();
    })->with(['active', 'trialing', 'past_due']);

    it('falls back to Free when a subscription is cancelled or has no standing', function (string $status) {
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), status: $status, periodEnd: now()->addDays(10));

        expect(Entitlements::for($tenant)->check(Feature::Customers)->limit())->toBe(Feature::FREE_CUSTOMERS);
    })->with(['canceled', 'expired']);

    it('keeps Pro through the grace period after a missed renewal, then reverts to Free', function () {
        config(['qistas.billing.grace_days' => 7]);
        $this->travelTo('2026-10-07 12:00:00');
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), status: 'active', periodEnd: now()->subDays(6));
        expect(Entitlements::for($tenant)->check(Feature::Customers)->unlimited())->toBeTrue();

        $this->travelTo('2026-10-09 12:00:00');
        expect(Entitlements::for($tenant)->check(Feature::Customers)->limit())->toBe(Feature::FREE_CUSTOMERS);
    });

    it('treats a subscription with no end date as current', function () {
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole(), status: 'active', periodEnd: null);

        expect(Entitlements::for($tenant)->check(Feature::Customers)->unlimited())->toBeTrue();
    });

    it('changes plan in place, never leaving two subscriptions', function () {
        $tenant = tenantOn('free');
        $tenant->subscribeTo(Plan::where('key', 'pro')->sole());

        expect($tenant->subscription()->count())->toBe(1)
            ->and($tenant->fresh()->subscription->plan->key)->toBe('pro');
    });
});

describe('the API payload', function () {
    it('lists the plan and every feature with a uniform shape', function () {
        usage(Feature::Customers, 3);

        $payload = Entitlements::for(tenantOn('free'))->toArray();

        expect($payload['plan'])->toBe(['key' => 'free', 'name' => 'Free'])
            ->and(array_keys($payload['features']))->toBe(array_map(fn (Feature $f) => $f->value, Feature::cases()))
            ->and($payload['features']['customers'])->toBe([
                'type' => 'limit', 'status' => 'on', 'detail' => null, 'enabled' => true, 'limit' => 20, 'used' => 3, 'remaining' => 17, 'unlimited' => false,
            ])
            ->and($payload['features']['pdf_statements'])->toBe([
                'type' => 'quota', 'status' => 'on', 'detail' => null, 'enabled' => true, 'limit' => 5, 'used' => 0, 'remaining' => 5, 'unlimited' => false,
            ])
            ->and($payload['features']['export_csv'])->toBe([
                'type' => 'toggle', 'status' => 'plan_locked', 'detail' => null, 'enabled' => false, 'limit' => null, 'used' => null, 'remaining' => null, 'unlimited' => false,
            ]);
    });

    it('marks Pro features as unlimited', function () {
        $features = Entitlements::for(tenantOn('pro'))->toArray()['features'];

        expect($features['customers'])->toMatchArray(['enabled' => true, 'limit' => null, 'remaining' => null, 'unlimited' => true])
            ->and($features['export_csv'])->toMatchArray(['enabled' => true, 'unlimited' => false]);
    });
});
