<?php

use App\Actions\CancelContract;
use App\Actions\RecordCustomerPayment;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Domain\PayPattern\RecomputePayPattern;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerPayPattern;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

/*
| Daily-habit wave A, H6: keeping each customer's stored payday pattern current. The calculator itself is pinned in
| PayPatternCalculatorTest; these tests pin what is fed to it and when it runs:
|
|   - after a payment and after a void, once the money is safely written, and never in a way that can fail either;
|   - every night, for the workspaces that have the feature on, checked when it runs and not when it was scheduled;
|   - from untagged payments only, on monthly contracts that are not open accounts, never from a payment that has been
|     reversed, and only from the last 18 calendar months of the workspace's own calendar;
|   - on the workspace's own dates (Riyadh, UTC+3, unless a test says otherwise), never UTC ones.
|
| The clock stands at 15:00 on 28 Oct 2026 in Riyadh (12:00 UTC). The 18 months it reads begin on 1 May 2025 there, which
| is 21:00 UTC on 30 April 2025.
*/

/** A workspace on the Pro plan (payday learning is on for it, once the platform switch is) whose clock is [$timezone]. */
function learnShop(string $plan = 'pro', string $timezone = 'Asia/Riyadh', string $country = 'SA'): Tenant
{
    $tenant = Tenant::factory()->create(['country' => $country, 'timezone' => $timezone]);
    $tenant->subscribeTo(Plan::where('key', $plan)->sole());

    return $tenant;
}

/**
 * A scheduled contract with room for many payments (36 instalments of 100.00) for [$customer] (a new one when none is given).
 *
 * @param  array<string, mixed>  $overrides
 */
function learnContract(Tenant $tenant, ?Customer $customer = null, array $overrides = []): Contract
{
    return openContract($tenant, array_merge([
        'customer_id' => ($customer ?? customerIn($tenant))->id, 'principal' => '3600.00', 'installment_count' => 36,
        'start_date' => '2025-01-01', 'first_due_date' => '2025-02-01',
    ], $overrides));
}

/** 100.00 taken on [$contract] at the UTC moment [$at], untagged unless [$tag] says otherwise. */
function learnPay(Contract $contract, string $at, ?string $tag = null, ?string $key = null): Transaction
{
    return app(RecordPayment::class)->handle($contract, '100.00', 'cash', $key, paidAt: CarbonImmutable::parse($at, 'UTC'), tag: $tag);
}

/** A payment on the 27th (noon in Riyadh is 09:00 UTC) of each of the given months ('2026-07', ...). */
function learnPayOn27th(Contract $contract, string ...$months): void
{
    foreach ($months as $month) {
        learnPay($contract, "{$month}-27 09:00:00");
    }
}

/** The stored pattern of [$customer], read as its own workspace does. */
function learnRow(Customer $customer): ?CustomerPayPattern
{
    return asTenant($customer->tenant, fn () => CustomerPayPattern::query()->where('customer_id', $customer->id)->first());
}

/** What a stored row says, as one array so a test can compare the whole answer at once. @return array{day: int, spread: int, months: int, within: int, confidence: string}|null */
function learnShape(?CustomerPayPattern $row): ?array
{
    return $row === null ? null : ['day' => $row->day, 'spread' => $row->spread, 'months' => $row->months, 'within' => $row->within, 'confidence' => $row->confidence];
}

/** How many patterns [$tenant] has stored. */
function learnCount(Tenant $tenant): int
{
    return asTenant($tenant, fn () => CustomerPayPattern::query()->count());
}

/** Forget what is stored for [$tenant], as if nothing had been worked out yet. */
function learnForget(Tenant $tenant): void
{
    asTenant($tenant, fn () => CustomerPayPattern::query()->delete());
}

/** Run the nightly command. */
function learnRun(): void
{
    test()->artisan('qistas:learn-paydays')->assertSuccessful();
}

/** The same four-month history for a customer: the 27th of July, August, September and October 2026. */
const LEARN_FOUR_MONTHS = ['2026-07', '2026-08', '2026-09', '2026-10'];

beforeEach(function () {
    $this->travelTo('2026-10-28 12:00:00');
    switchOn(Feature::PaydayLearning);
    switchOn(Feature::OpenContracts);
    $this->shop = learnShop();
    $this->customer = customerIn($this->shop);
    $this->contract = learnContract($this->shop, $this->customer);
});

test('a payment recomputes the customer\'s pattern', function () {
    learnPayOn27th($this->contract, '2026-07', '2026-08');
    // Two months say nothing yet.
    expect(learnRow($this->customer))->toBeNull();

    learnPayOn27th($this->contract, '2026-09');
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);

    // The row is replaced, not added to, and says when it was worked out.
    learnPayOn27th($this->contract, '2026-10');
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnCount($this->shop))->toBe(1)
        ->and(learnRow($this->customer)->computed_at->toDateTimeString())->toBe('2026-10-28 12:00:00')
        ->and(learnRow($this->customer)->tenant_id)->toBe($this->shop->id);
});

test('a payment across several contracts recomputes the customer\'s pattern too', function () {
    $second = learnContract($this->shop, $this->customer);
    learnPayOn27th($this->contract, '2026-07', '2026-08');

    // One payment from the customer, shared out over their contracts and written in one transaction of its own.
    app(RecordCustomerPayment::class)->handle($this->customer, '200.00', 'cash', paidAt: CarbonImmutable::parse('2026-09-27 09:00:00', 'UTC'));

    expect($second->id)->not->toBe($this->contract->id)
        ->and(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('a void recomputes it, and the pattern disappears when fewer than three months remain', function () {
    foreach (LEARN_FOUR_MONTHS as $month) {
        $payments[$month] = learnPay($this->contract, "{$month}-27 09:00:00");
    }
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high']);

    app(VoidTransaction::class)->handle($payments['2026-10']);
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);

    app(VoidTransaction::class)->handle($payments['2026-09']);
    expect(learnRow($this->customer))->toBeNull()
        ->and(learnCount($this->shop))->toBe(0);
});

test('voided payments are excluded', function () {
    learnPayOn27th($this->contract, '2026-06', '2026-07', '2026-08', '2026-09');
    // October: an early payment on the 3rd, taken by mistake and voided, then the real one on the 27th.
    $mistake = learnPay($this->contract, '2026-10-03 09:00:00');
    learnPayOn27th($this->contract, '2026-10');
    $withMistake = learnShape(learnRow($this->customer));
    app(VoidTransaction::class)->handle($mistake);

    // With the mistake October counted as the 3rd (4 of 5 months within 3 days of the 27th); without it, as the 27th.
    expect($withMistake)->toBe(['day' => 27, 'spread' => 0, 'months' => 5, 'within' => 80, 'confidence' => 'high'])
        ->and(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 5, 'within' => 100, 'confidence' => 'high']);

    // The nightly run reads the same ledger and says the same.
    learnForget($this->shop);
    learnRun();
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 5, 'within' => 100, 'confidence' => 'high']);
});

test('weekly and open contracts are ignored', function () {
    // Three months on the 27th on the monthly contract, which is all that should count.
    learnPayOn27th($this->contract, '2026-08', '2026-09', '2026-10');

    $weekly = learnContract($this->shop, $this->customer, ['frequency' => 'weekly']);
    $fortnightly = learnContract($this->shop, $this->customer, ['frequency' => 'biweekly']);
    $open = openContract($this->shop, ['customer_id' => $this->customer->id, 'type' => 'open', 'start_date' => '2025-01-01']);
    foreach (['2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $month) {
        learnPay($weekly, "{$month}-05 09:00:00");
        learnPay($fortnightly, "{$month}-09 09:00:00");
        learnPay($open, "{$month}-15 09:00:00");
    }

    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);

    learnForget($this->shop);
    learnRun();
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('only untagged payments count: no advance, refund, early discount, unpaid mark or down payment', function () {
    learnPayOn27th($this->contract, '2026-07', '2026-08', '2026-09');

    // Each of these would be a month of its own, on the 3rd, if it counted. One for every tag a line may carry.
    expect(Transaction::TAGS)->toBe(['advance', 'refund', 'early_discount', 'unpaid']);
    foreach (array_combine(Transaction::TAGS, ['2026-06', '2026-05', '2026-10', '2026-04']) as $tag => $month) {
        learnPay($this->contract, "{$month}-03 09:00:00", $tag);
    }
    // And a down payment, which is the contract's own term and a day chosen at the counter.
    learnContract($this->shop, $this->customer, ['down_payment' => '200.00', 'start_date' => '2026-03-03', 'first_due_date' => '2026-04-03']);

    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);

    learnForget($this->shop);
    learnRun();
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('payments on a cancelled contract still show when the customer paid', function () {
    learnPayOn27th($this->contract, '2026-08', '2026-09', '2026-10');
    app(CancelContract::class)->handle($this->contract);
    learnForget($this->shop);

    app(RecomputePayPattern::class)->handle($this->customer);

    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('a payment at 23:30 UTC on the 26th counts as the 27th in Riyadh', function () {
    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        learnPay($this->contract, "{$month}-26 23:30:00");
    }

    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);

    // And the other way round, west of UTC: 02:00 UTC on the 27th is still the evening of the 26th in New York.
    $newYork = learnShop(timezone: 'America/New_York', country: 'US');
    $payer = customerIn($newYork);
    $contract = learnContract($newYork, $payer);
    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        learnPay($contract, "{$month}-27 02:00:00");
    }
    expect(learnShape(learnRow($payer)))->toBe(['day' => 26, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);

    // The month is the local one as well: 22:30 UTC on the last day of a month is 01:30 on the 1st of the next in Riyadh.
    $monthEnd = customerIn($this->shop);
    $monthEndContract = learnContract($this->shop, $monthEnd);
    foreach (['2026-06-30', '2026-07-31', '2026-08-31'] as $evening) {
        learnPay($monthEndContract, "{$evening} 22:30:00");
    }
    expect(learnShape(learnRow($monthEnd)))->toBe(['day' => 1, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium']);
});

test('only the last 18 calendar months of the workspace\'s calendar are read, to the minute', function () {
    // The window begins at 00:00 on 1 May 2025 in Riyadh, which is 21:00 UTC on 30 April 2025.
    $tooOld = customerIn($this->shop);
    $tooOldContract = learnContract($this->shop, $tooOld);
    // 23:59 on 30 April there is one minute too old: it would be a fourth month, on the 30th, which counts as the 28th.
    learnPay($tooOldContract, '2025-04-30 20:59:00');
    foreach (['2026-08', '2026-09', '2026-10'] as $month) {
        learnPay($tooOldContract, "{$month}-28 09:00:00");
    }

    $justInside = customerIn($this->shop);
    $justInsideContract = learnContract($this->shop, $justInside);
    // Midnight, the first instant of the window, is in: a fourth month, on the 1st, with the three that follow on the 1st.
    learnPay($justInsideContract, '2025-04-30 21:00:00');
    foreach (['2026-07-31', '2026-08-31', '2026-09-30'] as $evening) {
        learnPay($justInsideContract, "{$evening} 21:00:00"); // 00:00 on the 1st of August, September and October in Riyadh
    }

    expect(learnShape(learnRow($tooOld)))->toBe(['day' => 28, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium'])
        ->and(learnShape(learnRow($justInside)))->toBe(['day' => 1, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high']);

    learnForget($this->shop);
    learnRun();
    expect(learnShape(learnRow($tooOld)))->toBe(['day' => 28, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium'])
        ->and(learnShape(learnRow($justInside)))->toBe(['day' => 1, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high']);
});

test('a customer who last paid years ago keeps no pattern, and a stored one is removed', function () {
    $lapsed = customerIn($this->shop);
    $contract = learnContract($this->shop, $lapsed, ['start_date' => '2023-01-01', 'first_due_date' => '2023-02-01']);
    learnPayOn27th($contract, '2023-03', '2023-04', '2023-05', '2023-06', '2023-07');

    expect(learnRow($lapsed))->toBeNull();

    // A row from the days when they did pay: the nightly run takes it away.
    asTenant($this->shop, fn () => CustomerPayPattern::query()->create([
        'customer_id' => $lapsed->id, 'day' => 27, 'spread' => 0, 'months' => 5, 'within' => 100, 'confidence' => 'high', 'computed_at' => '2023-08-01 00:00:00',
    ]));
    expect(learnCount($this->shop))->toBe(1);

    learnRun();
    expect(learnRow($lapsed))->toBeNull()
        ->and(learnCount($this->shop))->toBe(0);
});

test('a soft-deleted customer gets no row, and its stored row is deleted', function () {
    learnPayOn27th($this->contract, '2026-08', '2026-09', '2026-10');
    expect(learnRow($this->customer))->not->toBeNull();

    $this->customer->delete();
    app(RecomputePayPattern::class)->handle($this->customer);
    expect(learnRow($this->customer))->toBeNull();

    // Not made again by the nightly run, nor by a payment taken on the contract it left behind.
    learnRun();
    learnPay($this->contract, '2026-10-27 10:00:00');
    expect(learnRow($this->customer))->toBeNull()
        ->and(learnCount($this->shop))->toBe(0);
});

test('a replayed payment writes nothing and recomputes nothing', function () {
    learnPayOn27th($this->contract, '2026-08', '2026-09');
    $first = learnPay($this->contract, '2026-10-27 09:00:00', key: 'learn-replay-1');
    expect(learnShape(learnRow($this->customer)))->not->toBeNull();

    // Anything the first call left is gone; the replay finds the first line and writes nothing, so nothing is worked out again.
    learnForget($this->shop);
    $again = learnPay($this->contract, '2026-10-27 09:00:00', key: 'learn-replay-1');

    expect($again->id)->toBe($first->id)
        ->and(learnRow($this->customer))->toBeNull()
        ->and(asTenant($this->shop, fn () => Transaction::query()->where('type', 'payment')->count()))->toBe(3);
});

test('with the feature off nothing is written, even by the nightly command', function () {
    // Off for everyone while the payments are taken: three workspaces on the Pro plan, each with four months on the 27th.
    switchOff(Feature::PaydayLearning);
    $demo = learnShop();
    $demo->forceFill(['is_demo' => true])->save();
    $suspended = learnShop();
    $suspended->forceFill(['status' => 'suspended'])->save();
    foreach ([$this->contract, learnContract($demo), learnContract($suspended)] as $contract) {
        learnPayOn27th($contract, ...LEARN_FOUR_MONTHS);
    }
    expect(learnCount($this->shop) + learnCount($demo) + learnCount($suspended))->toBe(0);

    // On for the platform but not in the plan: a Free workspace has no payday learning.
    switchOn(Feature::PaydayLearning);
    $free = learnShop('free');
    learnPayOn27th(learnContract($free), ...LEARN_FOUR_MONTHS);
    expect(learnCount($free))->toBe(0);

    // A row from the days the feature was on in the Pro workspace.
    asTenant($this->shop, fn () => CustomerPayPattern::query()->create([
        'customer_id' => $this->customer->id, 'day' => 5, 'spread' => 1, 'months' => 6, 'within' => 90, 'confidence' => 'high', 'computed_at' => '2026-09-01 00:00:00',
    ]));
    $kept = learnShape(learnRow($this->customer));

    // Off again: the command and a direct call read no payment and write no pattern, and the row is left as it was.
    switchOff(Feature::PaydayLearning);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    learnRun();
    app(RecomputePayPattern::class)->handle($this->customer);
    $touched = array_values(array_filter($queries, fn (string $sql) => str_contains($sql, 'transactions') || str_contains($sql, 'customer_pay_patterns')));

    expect($touched)->toBe([])
        ->and(learnShape(learnRow($this->customer)))->toBe($kept)
        ->and(learnRow($this->customer)->computed_at->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and(learnCount($this->shop))->toBe(1);

    // On again, the nightly run works the Pro workspace out, and leaves the Free, demo and suspended ones alone.
    switchOn(Feature::PaydayLearning);
    learnRun();
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnCount($free))->toBe(0)
        ->and(learnCount($demo))->toBe(0)
        ->and(learnCount($suspended))->toBe(0);
});

test('an exception in the recompute never fails the payment', function () {
    Exceptions::fake();
    learnPayOn27th($this->contract, '2026-07', '2026-08');
    app()->bind(RecomputePayPattern::class, fn () => throw new RuntimeException('the pattern broke'));

    // Taking money: the line is written and returned as if nothing had happened.
    $payment = learnPay($this->contract, '2026-09-27 09:00:00');
    expect($payment->exists)->toBeTrue()
        ->and(asTenant($this->shop, fn () => Transaction::query()->where('type', 'payment')->count()))->toBe(3);

    // Cancelling it: the reversal is written.
    $reversal = app(VoidTransaction::class)->handle($payment);
    expect($reversal->type)->toBe('reversal')
        ->and($reversal->amount)->toBe('-100.0000');

    // Over the API, where an error would be a failed request for the person at the counter.
    [$owner, $apiShop] = apiOwner(['country' => 'SA', 'timezone' => 'Asia/Riyadh']);
    $apiContract = learnContract($apiShop);
    $this->postJson("/api/v1/contracts/{$apiContract->id}/payments", ['amount' => '100.00', 'method' => 'cash'])
        ->assertCreated();

    // It was reported, once for each line that asked, so someone can look at it.
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'the pattern broke');
    Exceptions::assertReportedCount(3);
});

test('a payment that is a settlement carries on when the recompute breaks', function () {
    Exceptions::fake();
    app()->bind(RecomputePayPattern::class, fn () => throw new RuntimeException('the pattern broke'));

    $settled = app(RecordPayment::class)->settle($this->contract, 'none', '0', '3600.00', 'cash');

    expect($settled->tag)->toBe('early_discount')
        ->and(asTenant($this->shop, fn () => Contract::query()->findOrFail($this->contract->id)->status))->toBe('settled');
    Exceptions::assertReportedCount(1);
});

test('the nightly command is idempotent', function () {
    $quiet = customerIn($this->shop);
    learnPayOn27th(learnContract($this->shop, $quiet), '2026-09', '2026-10'); // too few months: no row, before or after

    switchOff(Feature::PaydayLearning);
    foreach (LEARN_FOUR_MONTHS as $month) {
        learnPayOn27th($this->contract, $month);
    }
    switchOn(Feature::PaydayLearning);
    expect(learnCount($this->shop))->toBe(0);

    learnRun();
    $first = learnRow($this->customer);
    expect(learnShape($first))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high']);

    $this->travelTo('2026-10-29 00:05:00');
    learnRun();
    $second = learnRow($this->customer);

    // The same row, worked out again: one for the customer, none for the one with too little, nothing doubled.
    expect($second->id)->toBe($first->id)
        ->and(learnShape($second))->toBe(learnShape($first))
        ->and($second->computed_at->toDateTimeString())->toBe('2026-10-29 00:05:00')
        ->and(learnRow($quiet))->toBeNull()
        ->and(learnCount($this->shop))->toBe(1);
});

test('the nightly command carries on with the other workspaces when one of them fails, and says which', function () {
    Log::spy();
    switchOff(Feature::PaydayLearning);
    $first = learnShop();
    $broken = learnShop();
    $last = learnShop();
    $payers = [];
    foreach ([$first, $broken, $last] as $shop) {
        $payers[$shop->id] = customerIn($shop, ['name' => 'Ahmad Ali']);
        learnPayOn27th(learnContract($shop, $payers[$shop->id]), '2026-08', '2026-09', '2026-10');
    }
    switchOn(Feature::PaydayLearning);
    // The order the command takes the workspaces in: the one that fails is neither first nor last.
    expect(Tenant::query()->where('status', 'active')->where('is_demo', false)->cursor()->pluck('id')->all())
        ->toBe([$this->shop->id, $first->id, $broken->id, $last->id]);

    // One workspace's customers cannot be read, with a message that names someone.
    Customer::retrieved(function (Customer $customer) use ($broken) {
        if ($customer->tenant_id === $broken->id) {
            throw new RuntimeException('Cannot read Ahmad Ali.');
        }
    });
    learnRun();

    expect(learnShape(learnRow($payers[$first->id])))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium'])
        ->and(learnShape(learnRow($payers[$last->id])))->toBe(['day' => 27, 'spread' => 0, 'months' => 3, 'within' => 100, 'confidence' => 'medium'])
        ->and(learnCount($broken))->toBe(0);
    // The failure is in the log with the workspace and the kind of exception, and nothing a person could be recognised by.
    Log::shouldHaveReceived('warning')->withArgs(fn (string $line, array $context = []) => $line === 'payday learning failed'
        && $context === ['tenant' => $broken->id, 'error' => RuntimeException::class])->once();
});

test('one workspace\'s payments never shape another\'s pattern', function () {
    $other = learnShop();
    $stranger = customerIn($other);
    $strangerContract = learnContract($other, $stranger);

    // Same months, a different payday, in the other workspace.
    learnPayOn27th($this->contract, ...LEARN_FOUR_MONTHS);
    foreach (LEARN_FOUR_MONTHS as $month) {
        learnPay($strangerContract, "{$month}-05 09:00:00");
    }

    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnShape(learnRow($stranger)))->toBe(['day' => 5, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnRow($this->customer)->tenant_id)->toBe($this->shop->id)
        ->and(learnRow($stranger)->tenant_id)->toBe($other->id);

    // Worked out for a customer while another workspace is the current one: the row is still its own workspace's, and the
    // context the caller had is put back.
    learnForget($this->shop);
    learnForget($other);
    $current = app(CurrentTenant::class);
    $current->use($other, function () use ($current, $other) {
        app(RecomputePayPattern::class)->handle($this->customer);
        expect($current->get()->is($other))->toBeTrue();
    });
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnRow($this->customer)->tenant_id)->toBe($this->shop->id)
        ->and(learnCount($other))->toBe(0);

    // And the nightly run keeps the two apart.
    learnRun();
    expect(learnShape(learnRow($this->customer)))->toBe(['day' => 27, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnShape(learnRow($stranger)))->toBe(['day' => 5, 'spread' => 0, 'months' => 4, 'within' => 100, 'confidence' => 'high'])
        ->and(learnCount($this->shop))->toBe(1)
        ->and(learnCount($other))->toBe(1);
});

test('it runs from the scheduler once a day, one run at a time', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'qistas:learn-paydays'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
