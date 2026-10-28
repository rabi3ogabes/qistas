<?php

use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Glance\GlanceSnapshot;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\Alerts\AlertQueries;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/*
| The home-screen widget reads this (daily-habit wave A, H3): today's money in a glance, on the workspace's own day, with
| the same counts as the morning summary. It answers `enabled: false` and nothing else when the feature is off, so an
| installed widget can fall back to "Open Qistas"; it names a customer only when the request asks for names.
|
| The workspace is in Saudi Arabia (Asia/Riyadh, UTC+3), so 12:00 UTC on 7 October 2026 is 15:00 there, the same day.
*/

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
});

/**
 * A signed-in owner whose workspace, on Wednesday 7 October 2026, has: Amal with 100.00 due today; Badr with two
 * instalments of 100.00 already late (1 September and 1 October); and Carim, Dina and Eli with 100.00 each due on
 * 9, 12 and 13 October. Every contract has later instalments too, a month apart, which are due on none of these days.
 */
function glanceWorkspace(): Tenant
{
    [, $workspace] = apiOwner();

    foreach (['Amal' => '2026-10-07', 'Badr' => '2026-09-01', 'Carim' => '2026-10-09', 'Dina' => '2026-10-12', 'Eli' => '2026-10-13'] as $name => $first) {
        openContract($workspace, [
            'customer_id' => customerIn($workspace, ['name' => $name, 'phone' => '+966500000001', 'national_id' => '1000000001'])->id,
            'first_due_date' => $first,
        ]);
    }

    return $workspace;
}

/** What the morning summary counts for the workspace on its own day, read straight from the query it uses. */
function summaryTotals(Tenant $tenant): array
{
    return asTenant($tenant, fn () => app(AlertQueries::class)->totals($tenant->localTime(now()->copy()), 30));
}

describe('the glance', function () {
    it('returns today’s counts and amounts exactly as the morning summary counts them', function () {
        switchOn(Feature::HomeWidget);
        $tenant = glanceWorkspace();

        $data = $this->getJson('/api/v1/glance')->assertOk()->json('data');
        $summary = summaryTotals($tenant);

        expect($data['enabled'])->toBeTrue()
            ->and($data['currency'])->toBe('SAR')
            ->and($data['due_count'])->toBe($summary['due_count'])->toBe(1)
            ->and($data['due_amount'])->toBe($summary['due_amount'])->toBe('100.00')
            ->and($data['late_count'])->toBe($summary['late_count'])->toBe(2)
            ->and($data['late_amount'])->toBe($summary['late_amount'])->toBe('200.00')
            ->and($data['generated_at'])->toBe('2026-10-07T12:00:00+00:00');
    });

    it('lists the first three: those due today, then those coming this week, soonest first', function () {
        switchOn(Feature::HomeWidget);
        glanceWorkspace();

        $next = $this->getJson('/api/v1/glance')->assertOk()->json('data.next');

        expect($next)->toBe([
            ['reference' => 'C-0001', 'amount' => '100.00', 'due_on' => '2026-10-07'],
            ['reference' => 'C-0003', 'amount' => '100.00', 'due_on' => '2026-10-09'],
            ['reference' => 'C-0004', 'amount' => '100.00', 'due_on' => '2026-10-12'],
        ]);
    });

    it('is filled by today’s instalments alone when there are three or more, in contract order', function () {
        switchOn(Feature::HomeWidget);
        [, $tenant] = apiOwner();
        $same = customerIn($tenant, ['name' => 'Same'])->id;
        foreach (range(1, 4) as $unused) {
            openContract($tenant, ['customer_id' => $same, 'first_due_date' => '2026-10-07']);
        }
        openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Later'])->id, 'first_due_date' => '2026-10-09']);

        $next = $this->getJson('/api/v1/glance')->assertOk()->json('data.next');

        expect(Arr::pluck($next, 'reference'))->toBe(['C-0001', 'C-0002', 'C-0003'])
            ->and(Arr::pluck($next, 'due_on'))->toBe(['2026-10-07', '2026-10-07', '2026-10-07']);
    });

    it('shows what is still owed on an instalment that was part paid', function () {
        switchOn(Feature::HomeWidget);
        [, $tenant] = apiOwner();
        $contract = openContract($tenant, ['first_due_date' => '2026-10-07']);
        app(RecordPayment::class)->handle($contract, '30.00', 'cash');

        $data = $this->getJson('/api/v1/glance')->assertOk()->json('data');

        expect($data['next'][0]['amount'])->toBe('70.00')->and($data['due_amount'])->toBe('70.00');
    });

    it('has an empty list and zero counts when nothing is due, late or coming', function () {
        switchOn(Feature::HomeWidget);
        apiOwner();

        $this->getJson('/api/v1/glance')->assertOk()->assertJsonPath('data.next', [])
            ->assertJsonPath('data.due_count', 0)->assertJsonPath('data.due_amount', '0.00')
            ->assertJsonPath('data.late_count', 0)->assertJsonPath('data.late_amount', '0.00');
    });

    it('counts on the workspace’s own day, not the UTC one', function () {
        switchOn(Feature::HomeWidget);
        // 22:30 UTC on the 7th is 01:30 on the 8th in Riyadh. There the 8th is today: Farid's instalment is due, and Amal's
        // of the 7th is already late (Badr's two are too).
        $this->travelTo('2026-10-07 22:30:00');
        $tenant = glanceWorkspace();
        openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Farid'])->id, 'first_due_date' => '2026-10-08']);

        $data = $this->getJson('/api/v1/glance')->assertOk()->json('data');

        expect($data['due_count'])->toBe(1)->and($data['late_count'])->toBe(3)
            ->and($data['next'][0])->toMatchArray(['due_on' => '2026-10-08'])
            ->and($data)->toMatchArray(Arr::except(summaryTotals($tenant), 'past_limit') + ['enabled' => true]);
    });
});

describe('customer names', function () {
    it('are never included unless asked', function () {
        switchOn(Feature::HomeWidget);
        glanceWorkspace();

        foreach (['/api/v1/glance', '/api/v1/glance?names=0'] as $url) {
            $body = $this->getJson($url)->assertOk();

            expect($body->json('data.next'))->not->toBeEmpty();
            foreach ($body->json('data.next') as $row) {
                expect($row)->not->toHaveKey('name');
            }
            expect($body->getContent())->not->toContain('Amal')->not->toContain('Carim');
        }
    });

    it('are included when names=1, and no phone number or national ID ever is', function () {
        switchOn(Feature::HomeWidget);
        glanceWorkspace();

        $body = $this->getJson('/api/v1/glance?names=1')->assertOk();

        expect(array_column($body->json('data.next'), 'name'))->toBe(['Amal', 'Carim', 'Dina'])
            ->and($body->getContent())->not->toContain('966500000001')->not->toContain('1000000001')->not->toContain('phone')->not->toContain('national');
    });

    it('are asked for with 0 or 1, and nothing else', function () {
        switchOn(Feature::HomeWidget);
        apiOwner();

        $this->getJson('/api/v1/glance?names=sometimes')->assertUnprocessable();
    });
});

describe('when the feature is not enabled', function () {
    it('answers enabled false and nothing else when the platform switch is off', function () {
        glanceWorkspace();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/glance')->assertOk()->assertExactJson(['data' => ['enabled' => false]]);
        $this->getJson('/api/v1/glance?names=1')->assertOk()->assertExactJson(['data' => ['enabled' => false]]);
        $reads = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'installments') || str_contains($sql, 'customers'));
        DB::disableQueryLog();

        // Off is off: nothing about the workspace's money is read, and no use is counted.
        expect($reads)->toBeEmpty()->and(DB::table('feature_usage_daily')->count())->toBe(0);
    });

    it('answers enabled false when the plan lacks it', function () {
        switchOn(Feature::HomeWidget);
        glanceWorkspace();
        Plan::where('key', 'free')->sole()->setFeature(Feature::HomeWidget, false);

        $this->getJson('/api/v1/glance')->assertOk()->assertExactJson(['data' => ['enabled' => false]]);
        expect(DB::table('feature_usage_daily')->count())->toBe(0);
    });

    it('reads again at every request, so switching it on and off is seen at once', function () {
        glanceWorkspace();
        $this->getJson('/api/v1/glance')->assertJsonPath('data.enabled', false);

        switchOn(Feature::HomeWidget);
        $this->getJson('/api/v1/glance')->assertJsonPath('data.enabled', true);

        // A feature that was used cannot be pulled without saying why, as the admin's cockpit asks.
        app(FeatureControl::class)->setState(Feature::HomeWidget, PlatformState::Off, 'Pulled while checking', null);
        $this->getJson('/api/v1/glance')->assertExactJson(['data' => ['enabled' => false]]);
    });
});

describe('who may read it', function () {
    it('needs a token', function () {
        switchOn(Feature::HomeWidget);

        $this->getJson('/api/v1/glance')->assertUnauthorized();
    });

    it('works for a viewer', function () {
        switchOn(Feature::HomeWidget);
        $tenant = glanceWorkspace();
        apiMember('viewer', $tenant);

        $this->getJson('/api/v1/glance')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.due_count', 1);
    });

    it('only counts this workspace’s instalments', function () {
        switchOn(Feature::HomeWidget);
        [, $tenant] = apiOwner();
        $other = Tenant::factory()->create();
        openContract($other, ['customer_id' => customerIn($other, ['name' => 'Zed'])->id, 'first_due_date' => '2026-10-07']);
        openContract($other, ['customer_id' => customerIn($other, ['name' => 'Yara'])->id, 'first_due_date' => '2026-09-01']);

        $mine = $this->getJson('/api/v1/glance?names=1')->assertOk();
        expect($mine->json('data.due_count'))->toBe(0)->and($mine->json('data.late_count'))->toBe(0)->and($mine->json('data.next'))->toBe([]);

        openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => 'Amal'])->id, 'first_due_date' => '2026-10-07']);

        $mine = $this->getJson('/api/v1/glance?names=1')->assertOk();
        expect($mine->json('data.due_count'))->toBe(1)->and(array_column($mine->json('data.next'), 'name'))->toBe(['Amal']);
    });
});

describe('what it costs', function () {
    it('reads in a handful of queries, however many instalments there are', function () {
        switchOn(Feature::HomeWidget);
        $tenant = glanceWorkspace();
        $queries = function () use ($tenant): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            GlanceSnapshot::for($tenant, true);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $few = $queries();
        foreach (range(1, 12) as $i) {
            openContract($tenant, ['customer_id' => customerIn($tenant, ['name' => "Later {$i}"])->id, 'first_due_date' => '2026-10-0'.(($i % 9) + 1)]);
        }

        expect($few)->toBeLessThanOrEqual(8)->and($queries())->toBe($few);
    });

    it('counts one use per read', function () {
        switchOn(Feature::HomeWidget);
        $tenant = glanceWorkspace();
        $hits = fn () => (int) DB::table('feature_usage_daily')->where(['tenant_id' => $tenant->id, 'feature_key' => 'home_widget', 'day' => '2026-10-07'])->value('hits');

        expect($hits())->toBe(0);

        $this->getJson('/api/v1/glance')->assertOk();
        expect($hits())->toBe(1);

        $this->getJson('/api/v1/glance?names=1')->assertOk();
        $this->getJson('/api/v1/glance')->assertOk();
        expect($hits())->toBe(3);
    });
});
