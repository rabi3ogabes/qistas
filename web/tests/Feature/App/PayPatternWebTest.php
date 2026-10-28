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
|     app's Dart copy is written from it. Pest cannot run a browser, so the function is run through Node here against a
|     table of hand-computed dates; the test skips, saying so, when Node is not installed.
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
            ->and($patterns[$this->ahmad->id])->toBe(['day' => 27, 'end_of_month' => false, 'name' => 'Ahmad'])
            ->and($patterns[$this->huda->id])->toBe(['day' => 28, 'end_of_month' => true, 'name' => 'Huda']);

        $response->assertDontSee($stranger->id)->assertDontSee($gone->id);
    });

    it('names the customer by their first name, in any script, and escapes it', function () {
        $arabic = customerIn($this->shop, ['name' => 'أحمد علي']);
        $one = customerIn($this->shop, ['name' => 'Madonna']);
        $markup = customerIn($this->shop, ['name' => '<b>Bold</b> "Quote" Customer']);
        foreach ([$arabic, $one, $markup] as $customer) {
            pwPattern($customer);
        }

        $response = pwForm($this, $this->boss);
        $patterns = pwConfig($response)['payPatterns'];

        expect($patterns[$arabic->id]['name'])->toBe('أحمد')
            ->and($patterns[$one->id]['name'])->toBe('Madonna')
            ->and($patterns[$markup->id]['name'])->toBe('<b>Bold</b>');
        $response->assertDontSee('<b>Bold</b>', false);
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
            ->and($messages['onPayday'])->toBe('This date is already their payday.');

        // The field holds what it always held: a month from today. The page never moves it, whoever is chosen.
        preg_match('/<input[^>]*name="first_due_date"[^>]*>/', $response->getContent(), $field);
        expect($field[0])->toContain('value="2026-11-07"')
            ->and(pwConfig($response)['initial']['firstDue'])->toBe('2026-11-07');

        $chosen = pwForm($this, $this->boss, '?customer='.$this->ahmad->id);
        preg_match('/<input[^>]*name="first_due_date"[^>]*>/', $chosen->getContent(), $field);
        expect($field[0])->toContain('value="2026-11-07"')
            ->and(pwConfig($chosen)['initial'])->toMatchArray(['firstDue' => '2026-11-07', 'customerId' => $this->ahmad->id]);
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

        foreach (['says', 'saysEnd', 'ask', 'onPayday'] as $key) {
            expect($arabic[$key])->not->toBe($english[$key])->and(preg_match('/[\x{0600}-\x{06FF}]/u', $arabic[$key]))->toBe(1);
        }
        expect($arabic['says'])->toContain(':name')->toContain(':day')->and($arabic['ask'])->toContain(':date');
    });

    it('has no patterns, no suggestion and no trace of either when there is none to give or the feature is off', function () {
        // On, but nobody has a pattern: nothing to suggest, so nothing is drawn.
        $response = pwForm($this, $this->boss)->assertDontSee('data-pay-suggestion', false)->assertDontSee('Use it');
        expect(pwConfig($response))->not->toHaveKey('payPatterns');

        pwPattern($this->ahmad);

        // Switched off for everyone, the pattern still stored.
        switchOff(Feature::PaydayLearning);
        $response = pwForm($this, $this->boss)->assertDontSee('data-pay-suggestion', false)->assertDontSee('Use it')->assertDontSee('payPatterns');
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
 * Every row: what is typed in the first-due field, the workspace's today, the pattern's day, whether it is the end of
 * the month, and the date expected back ('' for no suggestion). Computed by hand from the calendar, not by the code.
 *
 * @return list<array{0: string|null, 1: string, 2: int|float, 3: bool, 4: string, 5: string}>
 */
function pwRuleCases(): array
{
    $today = '2026-10-21';

    return [
        // The day D of the month typed: before D it is this month's, on D it is the same day, after D it is next month's.
        ['2026-11-07', $today, 27, false, '2026-11-27', 'typed before D'],
        ['2026-11-26', $today, 27, false, '2026-11-27', 'the day before D'],
        ['2026-11-27', $today, 27, false, '2026-11-27', 'typed on D'],
        ['2026-11-28', $today, 27, false, '2026-12-27', 'the day after D'],
        ['2026-11-30', $today, 27, false, '2026-12-27', 'the end of the month, after D'],
        ['2026-03-01', $today, 1, false, '2026-03-01', 'D is the 1st, typed on it'],
        ['2026-03-02', $today, 1, false, '2026-04-01', 'D is the 1st, typed after it'],
        ['2026-01-31', $today, 15, false, '2026-02-15', 'from the 31st into a short month'],
        ['2026-11-01', $today, 28, false, '2026-11-28', 'D is the 28th, not flagged as the end of the month'],
        ['2026-02-28', $today, 28, false, '2026-02-28', 'D is the 28th, typed on it in February'],
        ['2026-03-29', $today, 28, false, '2026-04-28', 'D is the 28th, typed the 29th'],
        // December rolls into the next year.
        ['2026-12-27', $today, 27, false, '2026-12-27', 'December, on D'],
        ['2026-12-28', $today, 27, false, '2027-01-27', 'December rolls over'],
        ['2026-12-31', $today, 1, false, '2027-01-01', 'New Year\'s Eve, D the 1st'],
        // A leap day typed is just a day after D.
        ['2028-02-29', $today, 27, false, '2028-03-27', 'the leap day, after D'],
        ['2028-02-01', $today, 27, false, '2028-02-27', 'leap February, before D'],
        // The end of the month: the last day of the month typed, whatever its length.
        ['2026-11-03', $today, 28, true, '2026-11-30', 'a 30-day month'],
        ['2026-10-03', $today, 28, true, '2026-10-31', 'a 31-day month'],
        ['2026-11-30', $today, 28, true, '2026-11-30', 'already on the last day of a 30-day month'],
        ['2026-10-31', $today, 28, true, '2026-10-31', 'already on the last day of a 31-day month'],
        ['2026-12-15', $today, 28, true, '2026-12-31', 'December'],
        ['2026-01-31', $today, 28, true, '2026-01-31', 'January'],
        ['2027-02-15', $today, 28, true, '2027-02-28', 'February in an ordinary year'],
        ['2028-02-15', $today, 28, true, '2028-02-29', 'February in a leap year'],
        ['2028-02-29', $today, 28, true, '2028-02-29', 'the leap day itself'],
        ['2100-02-10', $today, 28, true, '2100-02-28', '2100 is not a leap year'],
        ['2000-02-10', $today, 28, true, '2000-02-29', '2000 is a leap year'],
        ['2026-11-03', $today, 15, true, '2026-11-30', 'the end of the month wins over the day'],
        // Empty, or not a date on the calendar: the workspace's today stands in.
        ['', $today, 27, false, '2026-10-27', 'empty, today before D'],
        ['', '2026-10-27', 27, false, '2026-10-27', 'empty, today on D'],
        ['', '2026-10-28', 27, false, '2026-11-27', 'empty, today after D'],
        ['', '2026-12-28', 5, false, '2027-01-05', 'empty, in December'],
        ['', $today, 28, true, '2026-10-31', 'empty, end of the month'],
        [null, $today, 27, false, '2026-10-27', 'null'],
        ['2026-02-30', $today, 27, false, '2026-10-27', 'there is no 30 February'],
        ['2026-02-29', $today, 27, false, '2026-10-27', '2026 is not a leap year'],
        ['2026-13-01', $today, 27, false, '2026-10-27', 'there is no month 13'],
        ['2026-00-10', $today, 27, false, '2026-10-27', 'there is no month 0'],
        ['2026-04-31', $today, 27, false, '2026-10-27', 'April has 30 days'],
        ['0000-01-01', $today, 27, false, '2026-10-27', 'there is no year 0'],
        ['garbage', $today, 27, false, '2026-10-27', 'not a date'],
        ['2026-1-5', $today, 27, false, '2026-10-27', 'not written with two digits'],
        ['26-11-07', $today, 27, false, '2026-10-27', 'a two-digit year'],
        ['2026/11/07', $today, 27, false, '2026-10-27', 'slashes'],
        [' 2026-11-07', $today, 27, false, '2026-10-27', 'a leading space'],
        ["2026-11-07\n", $today, 27, false, '2026-10-27', 'a trailing newline'],
        ['2026-11-07T00:00', $today, 27, false, '2026-10-27', 'a time after it'],
        ['٢٠٢٦-١١-٠٧', $today, 27, false, '2026-10-27', 'Arabic-Indic digits'],
        // Neither usable, or no usable day: no suggestion.
        ['', '', 27, false, '', 'no today either'],
        ['garbage', 'nonsense', 27, false, '', 'neither is a date'],
        ['', '2026-02-30', 27, false, '', 'today is not a day either'],
        ['2026-11-07', $today, 0, false, '', 'day 0'],
        ['2026-11-07', $today, 29, false, '', 'day 29'],
        ['2026-11-07', $today, -3, false, '', 'a negative day'],
        ['2026-11-07', $today, 27.5, false, '', 'a fraction of a day'],
        ['2026-11-07', $today, 99, true, '', 'a day out of range, even at the end of the month'],
        // The ends of the calendar.
        ['0001-01-15', $today, 27, false, '0001-01-27', 'the first year is written with four digits'],
        ['9999-12-15', $today, 28, true, '9999-12-31', 'the last year, end of the month'],
        ['9999-12-20', $today, 27, false, '9999-12-27', 'the last year, before D'],
        ['9999-12-30', $today, 27, false, '', 'there is no year 10000'],
    ];
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
    it('is the one the script works out, run through Node on a table of hand-computed dates', function () {
        $script = <<<'JS'
            import { pathToFileURL } from 'node:url';
            const { nextOccurrence } = await import(pathToFileURL(process.argv[1]).href);
            let text = '';
            for await (const chunk of process.stdin) text += chunk;
            console.log(JSON.stringify(JSON.parse(text).map(([typed, today, day, endOfMonth]) => nextOccurrence(typed, today, day, endOfMonth))));
            JS;
        $cases = pwRuleCases();

        $run = new Process(['node', '--input-type=module', '-e', $script, base_path('resources/js/pay-day.js')]);
        $run->setInput(json_encode(array_map(fn (array $case) => array_slice($case, 0, 4), $cases), JSON_THROW_ON_ERROR));
        $run->mustRun();
        $got = json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $wrong = [];
        foreach ($cases as $i => [$typed, $today, $day, $end, $expected, $note]) {
            if (($got[$i] ?? null) !== $expected) {
                $wrong[] = sprintf('%s: typed %s, today %s, day %s%s -> expected %s, got %s', $note, json_encode($typed), $today, json_encode($day), $end ? ', end of month' : '', json_encode($expected), json_encode($got[$i] ?? null));
            }
        }

        expect(count($got))->toBe(count($cases))->and($wrong)->toBe([]);
    })->skip(fn () => ! pwNodeIsInstalled(), 'Node is not installed here, so the date rule in resources/js/pay-day.js was not run.');
});
