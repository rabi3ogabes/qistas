<?php

use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| Daily-habit wave A, H4, ruling R29: a contract's down payment is money in, and it belongs to the workspace's own day,
| like every payment typed with a date (PaymentDate). A contract dated today takes its down payment now; one dated
| another day takes it at the last second of that day on the workspace's clock. A contract sent without a date starts
| on the workspace's today, not the server's (UTC). Before this, the down payment was dated 00:00 UTC of the start
| date, which is the evening before west of UTC, and a contract opened in Riyadh between midnight and 03:00 started
| on the day before: with that day closed, the after-close rule refused the collector.
|
| The instalment schedule is untouched: the contract's start date and first due date mean what they always meant.
*/

/** The down payment line of [$tenant]'s contract [$id]. */
function downPaymentOf(Tenant $tenant, string $id): Transaction
{
    return asTenant($tenant, fn () => Transaction::query()->where('contract_id', $id)->where('type', 'down_payment')->sole());
}

/** [$by] opens [$sale] through the API and gets the new contract's id. */
function openThroughApi(User $by, array $sale): string
{
    Sanctum::actingAs($by, ['app']);

    return test()->postJson('/api/v1/contracts', $sale)->assertCreated()->json('data.id');
}

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
});

describe('in Riyadh (UTC+3)', function () {
    beforeEach(function () {
        $this->shop = closeShop();
        $this->sara = closeStaff($this->shop, 'Sara Collector');
        $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
        $this->contract = closeContract($this->shop);

        // The 21st was closed at 20:00; it is now 01:00 on the 22nd there, still 22:00 on the 21st in UTC.
        $this->travelTo('2026-10-21 17:00:00');
        $this->closed = closeDay($this->shop, '2026-10-21', $this->sara);
        $this->travelTo('2026-10-21 22:00:00');
    });

    test('a contract opened at 01:00 without a date starts that day, and its down payment is taken now', function () {
        $id = openThroughApi($this->sara, saleOn($this->contract, null));

        $contract = asTenant($this->shop, fn () => Contract::query()->findOrFail($id));
        expect($contract->start_date->format('Y-m-d'))->toBe('2026-10-22')
            ->and(downPaymentOf($this->shop, $id)->paid_at->toDateTimeString())->toBe('2026-10-21 22:00:00')
            ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);
    });

    test('the same contract typed for the closed day before is refused for a collector and allowed for an owner, with the audit row', function () {
        Sanctum::actingAs($this->sara, ['app']);
        $this->postJson('/api/v1/contracts', saleOn($this->contract, '2026-10-21'))
            ->assertUnprocessable()
            ->assertJsonPath('error.fields', ['start_date' => [__('That day is closed. Ask the owner or a manager.')]]);
        expect(asTenant($this->shop, fn () => Contract::query()->count()))->toBe(1);

        $id = openThroughApi($this->boss, saleOn($this->contract, '2026-10-21'));

        // The last second of the 21st in Riyadh.
        expect(downPaymentOf($this->shop, $id)->paid_at->toDateTimeString())->toBe('2026-10-21 20:59:59')
            ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([[
                'action' => 'day_close.change_after_close', 'subject_id' => $this->closed->id, 'user_id' => $this->boss->id, 'changes' => ['day' => '2026-10-21', 'change' => 'payment'],
            ]]);
    });

    test('the web form offers the workspace\'s today as the contract date', function () {
        $this->actingAs($this->boss)->get('/app/contracts/create')->assertOk()
            ->assertSee('value="2026-10-22"', false)
            ->assertDontSee('value="2026-10-21"', false);
    });
});

describe('in New York (UTC-4, UTC-5 from 1 Nov)', function () {
    beforeEach(function () {
        $this->shop = closeShop('US', 'America/New_York');
        $this->sara = closeStaff($this->shop, 'Sara Collector');
        $this->contract = closeContract($this->shop);
    });

    test('a collector opens a contract dated today at 19:00 after yesterday was closed', function () {
        $this->travelTo('2026-10-21 00:30:00'); // 20:30 on the 20th in New York
        closeDay($this->shop, '2026-10-20', $this->sara);
        $this->travelTo('2026-10-21 23:00:00'); // 19:00 on the 21st there, already the 21st's last hour in UTC

        $id = openThroughApi($this->sara, saleOn($this->contract, '2026-10-21'));

        // Taken now, on the 21st: not 00:00 UTC, which is 20:00 on the closed 20th there.
        expect(downPaymentOf($this->shop, $id)->paid_at->toDateTimeString())->toBe('2026-10-21 23:00:00')
            ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);
    });

    test('a contract dated on the 25-hour day takes its down payment at the end of that local day', function () {
        // The clocks go back on 1 Nov: that day runs from 04:00 UTC to 05:00 UTC on the 2nd.
        $this->travelTo('2026-11-05 15:00:00');

        $id = openThroughApi($this->sara, saleOn($this->contract, '2026-11-01'));

        expect(downPaymentOf($this->shop, $id)->paid_at->toDateTimeString())->toBe('2026-11-02 04:59:59');
    });

    test('a contract dated in the future keeps its down payment on that future day', function () {
        $this->travelTo('2026-10-21 15:00:00');

        $id = openThroughApi($this->sara, saleOn($this->contract, '2026-10-30'));

        // The last second of the 30th in New York (EDT), as a payment typed for that day would be.
        expect(downPaymentOf($this->shop, $id)->paid_at->toDateTimeString())->toBe('2026-10-31 03:59:59')
            ->and(asTenant($this->shop, fn () => Contract::query()->findOrFail($id)->start_date->format('Y-m-d')))->toBe('2026-10-30');
    });
});

test('a down payment dated today is taken at the moment the contract is opened', function () {
    $shop = closeShop();
    $contract = closeContract($shop);
    $owner = closeStaff($shop, 'Boss Owner', 'owner');
    $this->travelTo('2026-10-21 12:34:56');

    $id = openThroughApi($owner, saleOn($contract, '2026-10-21'));

    expect(downPaymentOf($shop, $id)->paid_at->toDateTimeString())->toBe('2026-10-21 12:34:56');
});
