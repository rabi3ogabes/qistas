<?php

use App\Entitlements\Feature;
use App\Models\Customer;
use App\Models\CustomerPayPattern;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;

/*
| Daily-habit wave A, H6 on the web workspace: what the stored payday pattern looks like to the person at the counter.
|
|   - The customer's page carries a quiet chip, "Usually pays around day 27 of the month" and how often that held, and
|     nothing at all without a pattern or with payday learning off (for the platform, or on the workspace's plan).
|   - The "open a contract" form carries the patterns of the customers in its own list, read in one query, and a
|     suggestion under the first-due date: "Ahmad usually pays around day 27. Use 27 Nov 2026?" with a "Use it" button.
|     Nothing about the date changes until the button is pressed; the page itself never moves it.
|   - The date the button sets is worked out in the browser by one small pure function (resources/js/pay-day.js), and the
|     app's Dart copy is written from it. Pest cannot run a browser, so the function is run through Node here against the
|     hand-computed dates in shared/payday-vectors.json, the file the app's Dart test reads too; the test skips, saying
|     so, when Node is not installed.
|   - Pest cannot run the page's script either, so the few pieces of markup it depends on are pinned: the button is a
|     plain button, the customer field is bound, the date field has its ref, the sentence is drawn as text (never html),
|     and the one place that is announced to a screen reader is a hidden status line apart from the sentence.
|
| The shop is on the Pro plan (Free has payday learning off) and the clock is 7 October 2026, noon, so the form offers
| 7 November 2026 as the first due date.
*/

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::PaydayLearning);
    [$this->boss, $this->shop] = owner(['currency' => 'SAR']);
    $this->shop->subscribeTo(Plan::where('key', 'pro')->sole());
    $this->ahmad = customerIn($this->shop, ['name' => 'Ahmad Ali']);
    $this->huda = customerIn($this->shop, ['name' => 'Huda Saleh']);
    $this->nobody = customerIn($this->shop, ['name' => 'Nora Newcomer']);
});

/**
 * A stored pattern for [$customer], as the nightly run would leave it: the 27th, from 6 months, 83 percent of them within
 * 3 days. Only the fields given in [$overrides] differ.
 *
 * @param  array<string, mixed>  $overrides
 */
function pwPattern(Customer $customer, array $overrides = []): CustomerPayPattern
{
    return asTenant($customer->tenant, fn () => CustomerPayPattern::query()->create(array_merge([
        'customer_id' => $customer->id, 'day' => 27, 'spread' => 2, 'months' => 6, 'within' => 83, 'confidence' => 'high', 'computed_at' => now(),
    ], $overrides)));
}

/** The customer's page, as [$user] sees it. */
function pwCustomerPage(object $test, User $user, Customer $customer, string $query = ''): TestResponse
{
    return $test->actingAs($user)->get(route('app.customers.show', $customer).$query)->assertOk();
}

/** The "open a contract" form, as [$user] sees it. */
function pwForm(object $test, User $user, string $query = ''): TestResponse
{
    return $test->actingAs($user)->get(route('app.contracts.create').$query)->assertOk();
}

/**
 * What the form hands its script: the JSON in the form's data-config.
 *
 * @return array<string, mixed>
 */
function pwConfig(TestResponse $response): array
{
    expect(preg_match('/data-config="([^"]*)"/', $response->getContent(), $found))->toBe(1);

    return json_decode(html_entity_decode($found[1], ENT_QUOTES | ENT_HTML5), true, flags: JSON_THROW_ON_ERROR);
}

/** The opening tag of the first <[$tag]> in the page whose own text holds [$needle]; the test fails when there is none. */
function pwTag(string $html, string $tag, string $needle): string
{
    preg_match_all('/<'.$tag.'\b[^>]*>/s', $html, $found);
    foreach ($found[0] as $candidate) {
        if (str_contains($candidate, $needle)) {
            return $candidate;
        }
    }

    test()->fail("There is no <{$tag}> with {$needle} in the page.");
}

describe('the customer page', function () {
    it('shows the chip when there is a pattern', function () {
        pwPattern($this->ahmad);
        pwPattern($this->huda, ['day' => 28, 'within' => 71]);

        pwCustomerPage($this, $this->boss, $this->ahmad)
            ->assertSee('data-pay-pattern', false)
            ->assertSee('Usually pays around day 27 of the month')
            ->assertSee('Seen in 83% of recent months')
            ->assertDontSee('Usually pays at the end of the month');

        pwCustomerPage($this, $this->boss, $this->huda)
            ->assertSee('Usually pays at the end of the month')
            ->assertSee('Seen in 71% of recent months')
            ->assertDontSee('Usually pays around day');
    });

    it('says it in the language of the page, and shows no date of its own', function () {
        pwPattern($this->ahmad, ['computed_at' => '2026-09-14 23:30:00']);

        pwCustomerPage($this, $this->boss, $this->ahmad, '?lang=ar')
            ->assertSee('dir="rtl"', false)
            ->assertSee('عادة ما يدفع حوالي يوم 27 من الشهر')
            ->assertSee('لوحظ في 83% من الأشهر الأخيرة')
            ->assertDontSee('2026-09-14')->assertDontSee('2026-09-15');
    });

    it('shows nothing without one or with the feature off', function () {
        pwPattern($this->ahmad);

        // A customer with nothing worked out.
        pwCustomerPage($this, $this->boss, $this->nobody)->assertDontSee('data-pay-pattern', false)->assertDontSee('Usually pays');

        // Switched off for everyone: the stored row stays, and is not shown.
        switchOff(Feature::PaydayLearning);
        pwCustomerPage($this, $this->boss, $this->ahmad)->assertDontSee('data-pay-pattern', false)->assertDontSee('Usually pays');

        // On for the platform but not in the workspace's plan.
        switchOn(Feature::PaydayLearning);
        $this->shop->subscribeTo(Plan::where('key', 'free')->sole());
        pwCustomerPage($this, $this->boss, $this->ahmad)->assertDontSee('data-pay-pattern', false)->assertDontSee('Usually pays');

        // And back again: the same row is there.
        $this->shop->subscribeTo(Plan::where('key', 'pro')->sole());
        pwCustomerPage($this, $this->boss, $this->ahmad)->assertSee('data-pay-pattern', false)->assertSee('Usually pays around day 27 of the month');
    });
});

describe('the contract form', function () {
    it('carries the patterns of the selectable customers and only theirs', function () {
        pwPattern($this->ahmad);
        pwPattern($this->huda, ['day' => 28]);

        // Another workspace's customer, a customer who was deleted, and a pattern whose customer is gone: none of them is in the list.
        $stranger = customerIn(Tenant::factory()->create(), ['name' => 'Stranger Danger']);
        asTenant($stranger->tenant, fn () => CustomerPayPattern::query()->create([
            'customer_id' => $stranger->id, 'day' => 5, 'spread' => 1, 'months' => 4, 'within' => 90, 'confidence' => 'high', 'computed_at' => now(),
        ]));
        $gone = customerIn($this->shop, ['name' => 'Gone Away']);
        pwPattern($gone, ['day' => 9]);
        asTenant($this->shop, fn () => $gone->delete());

        $response = pwForm($this, $this->boss);
        $patterns = pwConfig($response)['payPatterns'];

        expect(array_keys($patterns))->toEqualCanonicalizing([$this->ahmad->id, $this->huda->id])
            ->and($patterns[$this->ahmad->id])->toBe(['day' => 27, 'end_of_month' => false, 'name' => 'Ahmad Ali'])
            ->and($patterns[$this->huda->id])->toBe(['day' => 28, 'end_of_month' => true, 'name' => 'Huda Saleh']);

        $response->assertDontSee($stranger->id)->assertDontSee($gone->id);
    });

    it('names the customer by the whole name the list above shows, whatever it is', function () {
        // A name is not "first word, last word": an Arabic compound name, a title, a business and a very long name all stay whole.
        $names = [
            'عبد الله القحطاني', 'أبو بكر', 'Mr. Ahmad Ali', 'Mobile Zone', 'Madonna',
            'Muhammad Abdulrahman bin Abdulaziz bin Mohammed Al-Qahtani Al-Otaibi Al-Harbi Al-Ghamdi '.str_repeat('Z', 80),
        ];
        $customers = [];
        foreach ($names as $name) {
            $customers[$name] = customerIn($this->shop, ['name' => $name]);
            pwPattern($customers[$name]);
        }

        $patterns = pwConfig(pwForm($this, $this->boss))['payPatterns'];

        foreach ($names as $name) {
            expect($patterns[$customers[$name]->id]['name'])->toBe($name);
        }
    });

    it('escapes the name wherever the page puts it, and draws the sentence as text, never as html', function () {
        $markup = customerIn($this->shop, ['name' => '<b>Bold</b> "Quote" & Customer']);
        pwPattern($markup);

        $response = pwForm($this, $this->boss);
        $html = $response->getContent();

        // Not in the page as written: the list shows it escaped, the script's data holds it as escaped JSON inside an attribute (a raw
        // quote would end that attribute early, and pwConfig could not read the data back), and nothing draws it as html.
        expect($html)->not->toContain('<b>Bold</b>')->not->toContain('"Quote"')->not->toContain('& Customer')
            ->and($html)->toContain('&lt;b&gt;Bold')
            ->and(pwConfig($response)['payPatterns'][$markup->id]['name'])->toBe('<b>Bold</b> "Quote" & Customer');
        $response->assertSee('x-text="paySuggestionText"', false)->assertDontSee('x-html', false);
    });

    it('reads the patterns in one query, however many customers there are', function () {
        pwPattern($this->ahmad);

        $patternReads = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            pwForm($this, $this->boss);

            return collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'customer_pay_patterns'))->count();
        };

        $few = $patternReads();
        foreach (range(1, 8) as $number) {
            pwPattern(customerIn($this->shop, ['name' => "Customer {$number}"]), ['day' => $number]);
        }

        expect($few)->toBe(1)->and($patternReads())->toBe(1);
    });

    it('has the suggestion markup, and leaves the date field unchanged by default', function () {
        pwPattern($this->ahmad);

        $response = pwForm($this, $this->boss)
            ->assertSee('data-pay-suggestion', false)
            ->assertSee('Use it');
        $messages = pwConfig($response)['messages'];

        expect($messages['says'])->toBe(':name usually pays around day :day.')
            ->and($messages['saysEnd'])->toBe(':name usually pays at the end of the month.')
            ->and($messages['ask'])->toBe('Use :date?')
            ->and($messages['onPayday'])->toBe('This date is already their payday.')
            ->and($messages['dateSet'])->toBe('Date set to :date');

        // The field holds what it always held: a month from today. The page never moves it, whoever is chosen.
        preg_match('/<input[^>]*name="first_due_date"[^>]*>/', $response->getContent(), $field);
        expect($field[0])->toContain('value="2026-11-07"')
            ->and(pwConfig($response)['initial']['firstDue'])->toBe('2026-11-07');

        $chosen = pwForm($this, $this->boss, '?customer='.$this->ahmad->id);
        preg_match('/<input[^>]*name="first_due_date"[^>]*>/', $chosen->getContent(), $field);
        expect($field[0])->toContain('value="2026-11-07"')
            ->and(pwConfig($chosen)['initial'])->toMatchArray(['firstDue' => '2026-11-07', 'customerId' => $this->ahmad->id]);
    });

    it('has the markup the script depends on, since Pest cannot run the script', function () {
        pwPattern($this->ahmad);

        $response = pwForm($this, $this->boss);
        $html = $response->getContent();

        // The button only presses: inside a form, anything but a plain button would submit it. It is read together with its sentence.
        expect(pwTag($html, 'button', '@click="usePaySuggestion"'))->toContain('type="button"')->toContain('aria-describedby="pay-suggest-text"');
        // The customer field is what the suggestion follows, and its change is what announces it.
        expect(pwTag($html, 'select', 'name="customer_id"'))->toContain('x-model="customerId"')->toContain('@change="customerChanged"');
        // The date field is where the button writes, and where focus goes back to.
        expect(pwTag($html, 'input', 'name="first_due_date"'))->toContain('x-ref="firstDueField"')->toContain('x-model="firstDue"');
        $response->assertSee('x-text="paySuggestionText"', false)->assertDontSee('x-html', false);

        // The sentence itself is not a live region (it changes with every key typed in the date); one hidden line, always drawn and
        // outside what shows and hides, is what a screen reader is told, and only when a customer is chosen or the button pressed.
        expect(pwTag($html, 'p', 'id="pay-suggest-text"'))->toContain('x-text="paySuggestionText"')->not->toContain('role=')->not->toContain('aria-live');
        $announcer = pwTag($html, 'p', 'x-text="payAnnouncement"');
        expect($announcer)->toContain('role="status"')->toContain('class="sr-only"');
        expect(substr_count($html, 'x-text="payAnnouncement"'))->toBe(1);
        preg_match('/<div class="pay-suggest"[^>]*>.*?<\/button>\s*<\/div>/s', $html, $block);
        expect($block)->not->toBeEmpty()->and($block[0])->not->toContain('payAnnouncement');
    });

    it('brings back the customer and the date that were typed when the form comes back with a problem', function () {
        pwPattern($this->ahmad);

        $response = $this->withSession(['_old_input' => ['customer_id' => $this->ahmad->id, 'first_due_date' => '2026-12-05']])
            ->actingAs($this->boss)->get(route('app.contracts.create'))->assertOk();

        expect(pwConfig($response)['initial'])->toMatchArray(['firstDue' => '2026-12-05', 'customerId' => $this->ahmad->id]);
    });

    it('says it in the language of the page', function () {
        pwPattern($this->ahmad);

        $english = pwConfig(pwForm($this, $this->boss))['messages'];
        $arabic = pwConfig(pwForm($this, $this->boss, '?lang=ar'))['messages'];

        foreach (['says', 'saysEnd', 'ask', 'onPayday', 'dateSet'] as $key) {
            expect($arabic[$key])->not->toBe($english[$key])->and(preg_match('/[\x{0600}-\x{06FF}]/u', $arabic[$key]))->toBe(1);
        }
        expect($arabic['says'])->toContain(':name')->toContain(':day')->and($arabic['ask'])->toContain(':date')->and($arabic['dateSet'])->toContain(':date');
    });

    it('has no patterns, no suggestion and no trace of either when there is none to give or the feature is off', function () {
        // On, but nobody has a pattern: nothing to suggest, so nothing is drawn.
        $response = pwForm($this, $this->boss)->assertDontSee('data-pay-suggestion', false)->assertDontSee('Use it');
        expect(pwConfig($response))->not->toHaveKey('payPatterns');

        pwPattern($this->ahmad);

        // Switched off for everyone, the pattern still stored.
        switchOff(Feature::PaydayLearning);
        $response = pwForm($this, $this->boss)->assertDontSee('data-pay-suggestion', false)->assertDontSee('Use it')->assertDontSee('payPatterns')
            ->assertDontSee('payAnnouncement')->assertDontSee('pay-suggest-text');
        expect(pwConfig($response))->not->toHaveKey('payPatterns')
            ->and(pwConfig($response)['messages'])->not->toHaveKey('says');

        // On for the platform, missing from the plan.
        switchOn(Feature::PaydayLearning);
        $this->shop->subscribeTo(Plan::where('key', 'free')->sole());
        $response = pwForm($this, $this->boss)->assertDontSee('data-pay-suggestion', false)->assertDontSee('payPatterns');
        expect(pwConfig($response))->not->toHaveKey('payPatterns');
    });
});

/**
 * The hand-computed cases of the date rule, from shared/payday-vectors.json: the file the app's Dart test reads too, so the
 * two copies of the rule are held to the same answers. Every case: what is typed in the first-due field (null or '' when
 * empty), the workspace's today, the pattern's day, whether it is the end of the month, and the date expected back
 * ('' for no suggestion).
 *
 * @return list<array{typed: string|null, today: string, day: int, endOfMonth: bool, expected: string, edge: string}>
 */
function pwRuleCases(): array
{
    $vectors = json_decode((string) file_get_contents(dirname(base_path()).'/shared/payday-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

    return $vectors['cases'];
}

function pwNodeIsInstalled(): bool
{
    try {
        $probe = new Process(['node', '--version']);
        $probe->run();

        return $probe->isSuccessful();
    } catch (Throwable) {
        return false;
    }
}

describe('the date the button sets', function () {
    it('has its hand-computed vectors to run', function () {
        $cases = pwRuleCases();

        expect(count($cases))->toBeGreaterThanOrEqual(50);
        foreach ($cases as $case) {
            expect(array_keys($case))->toBe(['typed', 'today', 'day', 'endOfMonth', 'expected', 'edge'])
                ->and($case['typed'] === null || is_string($case['typed']))->toBeTrue()
                ->and($case['day'])->toBeInt()->and($case['endOfMonth'])->toBeBool()->and($case['expected'])->toBeString()->and($case['edge'])->toBeString();
        }
    });

    it('is the one the script works out, run through Node on the shared vectors', function () {
        $script = <<<'JS'
            import { pathToFileURL } from 'node:url';
            const { nextOccurrence } = await import(pathToFileURL(process.argv[1]).href);
            let text = '';
            for await (const chunk of process.stdin) text += chunk;
            console.log(JSON.stringify(JSON.parse(text).map(({ typed, today, day, endOfMonth }) => nextOccurrence(typed, today, day, endOfMonth))));
            JS;
        $cases = pwRuleCases();

        $run = new Process(['node', '--input-type=module', '-e', $script, base_path('resources/js/pay-day.js')]);
        $run->setInput(json_encode($cases, JSON_THROW_ON_ERROR));
        $run->mustRun();
        $got = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $wrong = [];
        foreach ($cases as $i => $case) {
            if (($got[$i] ?? null) !== $case['expected']) {
                $wrong[] = sprintf('%s: typed %s, today %s, day %s%s -> expected %s, got %s', $case['edge'], json_encode($case['typed']), $case['today'], $case['day'], $case['endOfMonth'] ? ', end of month' : '', json_encode($case['expected']), json_encode($got[$i] ?? null));
            }
        }

        expect(count($got))->toBe(count($cases))->and($wrong)->toBe([]);
    })->skip(fn () => ! pwNodeIsInstalled(), 'Node is not installed here, so the date rule in resources/js/pay-day.js was not run.');
});
