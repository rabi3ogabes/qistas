<?php

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\PlanSettings;
use App\Entitlements\UsageMeters;
use App\Models\Plan;
use App\Models\Tenant;
use App\Site\PricingCatalog;
use App\Support\Format;

describe('what a plan gives', function () {
    it('resolves every feature for the built-in plans from the documented defaults', function () {
        $free = PlanSettings::resolve(Plan::where('key', 'free')->sole());

        expect($free['customers'])->toBe(['enabled' => true, 'limit' => 20])
            ->and($free['pdf_statements'])->toBe(['enabled' => true, 'limit' => 5])
            ->and($free['export_csv'])->toBe(['enabled' => false, 'limit' => null])
            ->and(array_keys($free))->toBe(array_map(fn (Feature $f) => $f->value, Feature::cases()));
    });

    it('prefers what the admin set on the plan', function () {
        $plan = Plan::where('key', 'free')->sole();
        $plan->setFeature(Feature::Customers, enabled: true, limit: 12);

        expect(PlanSettings::resolve($plan)['customers'])->toBe(['enabled' => true, 'limit' => 12]);
    });

    it('gives a plan the admin created nothing until it is configured', function () {
        $plan = Plan::create(['name' => 'Studio', 'description' => null, 'sort_order' => 30, 'is_public' => true]);

        expect(collect(PlanSettings::resolve($plan))->every(fn ($v) => $v === ['enabled' => false, 'limit' => null]))->toBeTrue();
    });

    it('is exactly what the entitlement engine applies to a workspace on that plan', function (string $key) {
        // The plan against the engine, so every feature is switched on at the platform level (new ones ship off).
        foreach (Feature::cases() as $feature) {
            if (! $feature->isCore()) {
                switchOn($feature);
            }
        }
        $plan = Plan::where('key', $key)->sole();
        $plan->setFeature(Feature::ExportCsv, enabled: true);
        $tenant = Tenant::factory()->create();
        $tenant->subscribeTo($plan);
        foreach ([Feature::Customers, Feature::ActiveContracts, Feature::ApiTokens, Feature::Members] as $counted) {
            app(UsageMeters::class)->register($counted, fn () => 0);
        }

        $applied = Entitlements::for($tenant)->toArray()['features'];

        foreach (PlanSettings::resolve($plan) as $feature => $setting) {
            expect($applied[$feature]['enabled'])->toBe($setting['enabled'])->and($applied[$feature]['limit'])->toBe($setting['enabled'] ? $setting['limit'] : null);
        }
    })->with(['free', 'pro']);
});

describe('describing a feature in words', function () {
    it('says what a counted feature allows', function (Feature $feature, bool $enabled, ?int $limit, string $expected) {
        expect($feature->summary($enabled, $limit))->toBe($expected);
    })->with([
        'limited' => [Feature::Customers, true, 5, 'Up to 5 customers'],
        'single' => [Feature::ApiTokens, true, 1, 'Up to 1 API token'],
        'unlimited' => [Feature::Customers, true, null, 'Unlimited customers'],
        'monthly quota' => [Feature::PdfStatements, true, 3, 'Up to 3 PDF statements per month'],
        'monthly unlimited' => [Feature::PdfStatements, true, null, 'Unlimited PDF statements per month'],
        'switched off' => [Feature::Customers, false, null, 'Not included'],
    ]);

    it('names an on/off feature when it is included and says so when it is not', function () {
        expect(Feature::ExportCsv->summary(true, null))->toBe('CSV export')
            ->and(Feature::ExportCsv->summary(false, null))->toBe('Not included');
    });

    it('gives a short value for a comparison table', function (Feature $feature, bool $enabled, ?int $limit, string $expected) {
        expect($feature->shortValue($enabled, $limit))->toBe($expected);
    })->with([
        [Feature::Customers, true, 5, '5'],
        [Feature::Customers, true, null, 'Unlimited'],
        [Feature::PdfStatements, true, 3, '3 / month'],
        [Feature::PdfStatements, true, null, 'Unlimited'],
        [Feature::ExportCsv, true, null, 'Included'],
        [Feature::ExportCsv, false, null, 'Not included'],
        [Feature::Customers, false, null, 'Not included'],
    ]);
});

describe('the pricing catalogue', function () {
    it('lists the public plans in order with what each includes', function () {
        $offers = app(PricingCatalog::class)->offers();

        expect($offers->pluck('plan.key')->all())->toBe(['free', 'pro'])
            ->and($offers[0]->features())->toHaveCount(count(Feature::cases()))
            ->and($offers[0]->feature(Feature::Customers)['enabled'])->toBeTrue()
            ->and($offers[0]->feature(Feature::ExportCsv)['enabled'])->toBeFalse()
            ->and($offers[1]->feature(Feature::ExportCsv)['enabled'])->toBeTrue();
    });

    it('leaves out plans the admin hid', function () {
        Plan::create(['name' => 'Secret', 'description' => null, 'sort_order' => 50, 'is_public' => false]);

        expect(app(PricingCatalog::class)->offers()->pluck('plan.key')->all())->toBe(['free', 'pro']);
    });

    it('follows an admin edit on the very next read', function () {
        expect(app(PricingCatalog::class)->offers()[0]->feature(Feature::Customers)['limit'])->toBe(20);

        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 8);

        expect(app(PricingCatalog::class)->offers()[0]->feature(Feature::Customers)['limit'])->toBe(8);
    });

    it('knows what the free plan allows, for the copy that mentions it', function () {
        expect(app(PricingCatalog::class)->freeAllowance(Feature::Customers))->toBe(20);

        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 9);

        expect(app(PricingCatalog::class)->freeAllowance(Feature::Customers))->toBe(9);
    });

    it('prices a plan monthly and yearly, with the yearly saving', function () {
        $pro = app(PricingCatalog::class)->offers()[1];

        expect($pro->monthlyPrice())->not->toBeNull()
            ->and($pro->yearlyPrice())->not->toBeNull()
            ->and($pro->yearlySavingPercent())->toBeGreaterThan(0)
            ->and(app(PricingCatalog::class)->offers()[0]->isFree())->toBeTrue();
    });
});

describe('the pricing page', function () {
    it('shows both plans with their prices and a way to start', function () {
        $page = $this->get('/pricing');

        $page->assertOk()->assertSee('Free')->assertSee('Pro')->assertSee(route('register'), false);
    });

    it('shows exactly the features the admin enabled for each plan, and flips when the matrix changes', function () {
        $before = $this->get('/pricing')->getContent();
        expect($before)->toContain('Up to 20 customers');

        Plan::where('key', 'free')->sole()->setFeature(Feature::Customers, enabled: true, limit: 7);
        Plan::where('key', 'free')->sole()->setFeature(Feature::ExportCsv, enabled: true);

        $after = $this->get('/pricing')->getContent();
        expect($after)->toContain('Up to 7 customers')->not->toContain('Up to 20 customers');
        $this->get('/pricing')->assertSee('CSV export');
    });

    it('formats the price for the reader’s language with Western digits', function () {
        $this->get('/pricing?lang=ar')->assertOk()->assertSee('lang="ar"', false)->assertSee('12.00');
    });
});

describe('formatting money', function () {
    it('formats in the reader’s language and always with Western digits', function () {
        app()->setLocale('en');
        expect(Format::money('12', 'USD'))->toBe('$12.00');

        app()->setLocale('ar');
        expect(Format::money('1200.5', 'SAR'))->toMatch('/1,?200\.50/');
    });

    it('keeps cents exact', function () {
        app()->setLocale('en');

        expect(Format::money('0.07', 'USD'))->toBe('$0.07')->and(Format::money('33.34', 'USD'))->toBe('$33.34');
    });
});
