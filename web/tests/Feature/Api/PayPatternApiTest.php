<?php

use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\CustomerPayPattern;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
| Daily-habit wave A, H6: the payday pattern in the API. The calculator and the nightly recompute are pinned elsewhere
| (PayPatternCalculatorTest, RecomputeTest); this holds what the web page and the app are given to show next to a
| customer ("Usually pays around day 27 of the month").
|
|   - `pay_pattern` is on the customer's own page (GET /api/v1/customers/{customer}) and nowhere else: never on the list,
|     which must not grow a query per row, and never on what a change or a pin answers.
|   - The key is there only when payday learning is on for the workspace (the platform switch and the plan). On, a
|     customer with nothing worked out has `null`. Off, there is no key at all, even for a customer whose stored pattern
|     is still in the database; switched back on, it is there again.
|   - The words are written here, on the server, in the language of the request, and carry their numbers as given: the
|     apps show them as sent.
*/

beforeEach(function () {
    switchOn(Feature::PaydayLearning);
    [, $this->shop] = apiOwner();
    $this->shop->subscribeTo(Plan::where('key', 'pro')->sole());
    $this->ahmad = customerIn($this->shop, ['name' => 'Ahmad Ali']);
});

/**
 * A stored pattern for [$customer]: the 27th, from 6 months, 83 percent of them within 3 days, as the nightly run would
 * leave it. Only the fields given in [$overrides] differ.
 *
 * @param  array<string, mixed>  $overrides
 */
function payPatternOf(Customer $customer, array $overrides = []): CustomerPayPattern
{
    return asTenant($customer->tenant, fn () => CustomerPayPattern::query()->create(array_merge([
        'customer_id' => $customer->id, 'day' => 27, 'spread' => 2, 'months' => 6, 'within' => 83, 'confidence' => 'high', 'computed_at' => now(),
    ], $overrides)));
}

/** The customer's own page, as the person signed in sees it. */
function payPatternPage(Customer $customer, array $headers = []): TestResponse
{
    return test()->getJson("/api/v1/customers/{$customer->id}", $headers)->assertOk();
}

/**
 * The SQL of every query the request made.
 *
 * @return list<string>
 */
function payPatternQueries(string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->getJson($url)->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $queries;
}

describe('on the customer’s page', function () {
    it('shows the pattern for a customer who has one', function () {
        payPatternOf($this->ahmad);

        $page = payPatternPage($this->ahmad);

        // The whole answer at once: the keys, their order and their types are what the apps read.
        expect($page->json('data.pay_pattern'))->toBe([
            'day' => 27, 'spread' => 2, 'months' => 6, 'within' => 83, 'confidence' => 'high', 'end_of_month' => false,
            'label' => 'Usually pays around day 27 of the month', 'basis' => 'Seen in 83% of recent months',
        ]);
    });

    it('counts one use of the feature when a pattern is shown, and none when there is nothing to show', function () {
        $hits = fn () => (int) DB::table('feature_usage_daily')->where(['tenant_id' => $this->shop->id, 'feature_key' => 'payday_learning'])->sum('hits');

        payPatternPage($this->ahmad);
        expect($hits())->toBe(0);

        payPatternOf($this->ahmad);
        payPatternPage($this->ahmad);
        payPatternPage($this->ahmad);
        expect($hits())->toBe(2);

        switchOff(Feature::PaydayLearning);
        payPatternPage($this->ahmad);
        expect($hits())->toBe(2);
    });

    it('shows null when the customer has none', function () {
        $data = payPatternPage($this->ahmad)->json('data');

        // Present and empty is not the same as left out: it says the feature is on and there is nothing to say yet.
        expect($data)->toHaveKey('pay_pattern')
            ->and($data['pay_pattern'])->toBeNull();
    });

    it('shows a medium pattern as medium, with its own figures', function () {
        payPatternOf($this->ahmad, ['day' => 5, 'spread' => 6, 'months' => 3, 'within' => 67, 'confidence' => 'medium']);

        expect(payPatternPage($this->ahmad)->json('data.pay_pattern'))->toBe([
            'day' => 5, 'spread' => 6, 'months' => 3, 'within' => 67, 'confidence' => 'medium', 'end_of_month' => false,
            'label' => 'Usually pays around day 5 of the month', 'basis' => 'Seen in 67% of recent months',
        ]);
    });

    it('says the end of the month for the 28th to the 31st, and still gives the day', function () {
        payPatternOf($this->ahmad, ['day' => 28, 'spread' => 1, 'months' => 8, 'within' => 100]);

        // `day` stays 28 for the apps that suggest dates from it; only the words change.
        expect(payPatternPage($this->ahmad)->json('data.pay_pattern'))->toBe([
            'day' => 28, 'spread' => 1, 'months' => 8, 'within' => 100, 'confidence' => 'high', 'end_of_month' => true,
            'label' => 'Usually pays at the end of the month', 'basis' => 'Seen in 100% of recent months',
        ]);
    });

    it('reads the pattern of the customer asked for, not another one’s', function () {
        $layla = customerIn($this->shop, ['name' => 'Layla Haddad']);
        payPatternOf($this->ahmad, ['day' => 27]);
        payPatternOf($layla, ['day' => 10]);

        expect(payPatternPage($this->ahmad)->json('data.pay_pattern.day'))->toBe(27)
            ->and(payPatternPage($layla)->json('data.pay_pattern.day'))->toBe(10);
    });

    it('reads the pattern in one query', function () {
        payPatternOf($this->ahmad);

        $queries = payPatternQueries("/api/v1/customers/{$this->ahmad->id}");

        expect(array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'customer_pay_patterns'))))->toHaveCount(1);
    });
});

describe('when payday learning is off', function () {
    it('has no key at all, for a customer who still has a stored pattern', function (string $why) {
        payPatternOf($this->ahmad);

        if ($why === 'the platform has it off') {
            switchOff(Feature::PaydayLearning);
        } else {
            $this->shop->subscribeTo(Plan::where('key', 'free')->sole());
        }

        $data = payPatternPage($this->ahmad)->json('data');

        expect($data)->not->toHaveKey('pay_pattern')
            ->and($data['name'])->toBe('Ahmad Ali');
    })->with(['the platform has it off', 'the plan does not include it']);

    it('keeps the stored pattern, and shows it again when switched back on', function () {
        payPatternOf($this->ahmad);
        switchOff(Feature::PaydayLearning);
        expect(payPatternPage($this->ahmad)->json('data'))->not->toHaveKey('pay_pattern');

        switchOn(Feature::PaydayLearning);

        expect(payPatternPage($this->ahmad)->json('data.pay_pattern.label'))->toBe('Usually pays around day 27 of the month');
    });

    it('does not even look the pattern up', function () {
        payPatternOf($this->ahmad);
        switchOff(Feature::PaydayLearning);

        $queries = payPatternQueries("/api/v1/customers/{$this->ahmad->id}");

        expect(array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'customer_pay_patterns'))))->toBe([]);
    });
});

describe('everywhere else', function () {
    it('is not on the list, and the list does not grow a query per customer', function () {
        payPatternOf($this->ahmad);
        customerIn($this->shop, ['name' => 'Bilal']);
        $this->getJson('/api/v1/customers')->assertOk(); // the first request pays for anything set up once

        $few = payPatternQueries('/api/v1/customers');

        foreach (['Charbel', 'Dina', 'Elias', 'Farah'] as $name) {
            payPatternOf(customerIn($this->shop, ['name' => $name]));
        }
        $many = payPatternQueries('/api/v1/customers');

        $page = $this->getJson('/api/v1/customers')->assertOk();
        expect($page->json('data'))->toHaveCount(6);
        foreach ($page->json('data') as $row) {
            expect($row)->not->toHaveKey('pay_pattern');
        }
        expect(count($many))->toBe(count($few))
            ->and(array_values(array_filter($many, fn (string $sql) => str_contains($sql, 'customer_pay_patterns'))))->toBe([]);
    });

    it('is not on what adding, changing or pinning a customer answers', function () {
        payPatternOf($this->ahmad);

        $created = $this->postJson('/api/v1/customers', ['name' => 'Layla Haddad', 'phone' => '+966 50 123 4567'])->assertCreated();
        $changed = $this->putJson("/api/v1/customers/{$this->ahmad->id}", ['name' => 'Ahmad Ali', 'phone' => '+966 50 123 4567'])->assertOk();
        $pinned = $this->postJson("/api/v1/customers/{$this->ahmad->id}/pin")->assertOk();

        foreach ([$created, $changed, $pinned] as $response) {
            expect($response->json('data'))->not->toHaveKey('pay_pattern');
        }
    });
});

describe('who may see it', function () {
    it('is a 404, never the pattern, for another workspace’s customer', function () {
        $theirs = customerIn(Tenant::factory()->create(), ['name' => 'Someone Else']);
        payPatternOf($theirs);

        $response = $this->getJson("/api/v1/customers/{$theirs->id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');

        expect($response->getContent())->not->toContain('pay_pattern')->not->toContain('Usually');
    });

    it('is a 404 for a customer who has been deleted', function () {
        payPatternOf($this->ahmad);
        asTenant($this->shop, fn () => $this->ahmad->delete());

        $this->getJson("/api/v1/customers/{$this->ahmad->id}")->assertNotFound();
    });
});

describe('in the language of the request', function () {
    it('has the label and the basis in Arabic, with the numbers as they are', function () {
        payPatternOf($this->ahmad);

        $page = payPatternPage($this->ahmad, ['Accept-Language' => 'ar']);

        expect($page->headers->get('Content-Language'))->toBe('ar')
            ->and($page->json('data.pay_pattern.label'))->toBe('عادة ما يدفع حوالي يوم 27 من الشهر')
            ->and($page->json('data.pay_pattern.basis'))->toBe('لوحظ في 83% من الأشهر الأخيرة')
            // Only the words follow the language; the figures the apps compute with do not.
            ->and($page->json('data.pay_pattern.day'))->toBe(27)
            ->and($page->json('data.pay_pattern.confidence'))->toBe('high');
    });

    it('has the end of the month in Arabic', function () {
        payPatternOf($this->ahmad, ['day' => 28]);

        $page = payPatternPage($this->ahmad, ['Accept-Language' => 'ar']);

        expect($page->json('data.pay_pattern.label'))->toBe('عادة ما يدفع في نهاية الشهر')
            ->and($page->json('data.pay_pattern.end_of_month'))->toBeTrue();
    });

    it('has no English left in French, Spanish or Urdu, and keeps the numbers', function (string $lang) {
        payPatternOf($this->ahmad);
        payPatternOf($layla = customerIn($this->shop, ['name' => 'Layla Haddad']), ['day' => 28]);

        $day = payPatternPage($this->ahmad, ['Accept-Language' => $lang])->json('data.pay_pattern');
        $end = payPatternPage($layla, ['Accept-Language' => $lang])->json('data.pay_pattern');

        expect($day['label'])->toContain('27')->not->toContain('Usually')->not->toContain('month')->not->toContain(':day')
            ->and($day['basis'])->toContain('83')->not->toContain('Seen')->not->toContain('months')->not->toContain(':percent')
            ->and($end['label'])->not->toContain('Usually')->not->toContain('month')->not->toContain('27')
            ->and($end['basis'])->toContain('83');
    })->with(['French' => 'fr', 'Spanish' => 'es', 'Urdu' => 'ur']);

    it('follows the request each time, not the first one', function () {
        payPatternOf($this->ahmad);

        $arabic = payPatternPage($this->ahmad, ['Accept-Language' => 'ar'])->json('data.pay_pattern.label');
        $english = payPatternPage($this->ahmad, ['Accept-Language' => 'en'])->json('data.pay_pattern.label');

        expect($arabic)->toBe('عادة ما يدفع حوالي يوم 27 من الشهر')
            ->and($english)->toBe('Usually pays around day 27 of the month');
    });
});
