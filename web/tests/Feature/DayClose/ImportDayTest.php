<?php

use App\Actions\VoidTransaction;
use App\Domain\DayClose\SummaryText;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Transaction;
use App\Reports\CustomerBalances;
use App\Reports\DashboardMetrics;
use Laravel\Sanctum\Sanctum;

/*
| Daily-habit wave A, H4: the day a shop brings its books in. Each customer's "Paid before Qistas" is one payment on the
| import day, so the day's close would read a year of payments as one afternoon's takings, and every imported contract
| as its own "Contract opened" row. The import's balances are money the shop took before it used Qistas: they stay on the
| contracts (what each customer still owes, the reports), and stay out of every day's till. A run of contracts opened at
| once is one line worth a look, not hundreds.
|
| Al-Fares is in Riyadh (UTC+3). The clock stands at 14:00 there on 21 October 2026 (11:00 UTC) when the books come in.
*/

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
    switchOn(Feature::Import);
    switchOn(Feature::OpenContracts);
    $this->shop = closeShop();
    $this->shop->update(['name' => 'Al-Fares']);
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->contract = closeContract($this->shop);
});

/**
 * The owner brings in [$plans] customers who each bought for 1,200.00 and paid 300.00 before Qistas, and [$balances]
 * with a balance of 500.00 and no plan (an open contract each), at the moment the clock stands at.
 *
 * @return list<string> the ids of the contracts the import opened, the first first
 */
function idtImport(object $test, int $plans, int $balances = 0): array
{
    $rows = [['Name', 'Phone', 'Total', 'Paid', 'Instalments', 'First due date', 'Balance']];
    foreach (range(1, $plans) as $n) {
        $rows[] = ["Imported Customer {$n}", sprintf('05011%05d', $n), 1200, 300, 4, '2026-11-01', null];
    }
    foreach ($balances > 0 ? range(1, $balances) : [] as $n) {
        $rows[] = ["Open Customer {$n}", sprintf('05022%05d', $n), null, null, null, null, 500];
    }

    Sanctum::actingAs($test->boss, ['app']);
    $id = sendBooks(xlsxOf($rows))->assertCreated()->json('data.id');
    $test->postJson("/api/v1/imports/{$id}/confirm")->assertOk()->assertJsonPath('data.summary.contracts', $plans + $balances);

    return asTenant($test->shop, fn () => Contract::query()->where('created_at', '>=', now())->orderBy('number')->pluck('id')->all());
}

/** The sentence a close of the 21st reads, the no-break spaces of amounts made plain. */
function idtSentence(object $test, string $counted): string
{
    return str_replace("\u{00A0}", ' ', SummaryText::for(closeDay($test->shop, '2026-10-21', $test->sara, $counted), $test->shop));
}

describe('the day a shop brings its books in', function () {
    beforeEach(function () {
        // The morning's real takings, then the import in the afternoon, then one more customer pays in cash.
        $this->travelTo('2026-10-21 06:00:00');
        closeTake($this->contract, '100.00', by: $this->sara);
        closeTake($this->contract, '60.00', 'card', by: $this->sara);
        $this->travelTo('2026-10-21 11:00:00');
        $this->opened = idtImport($this, 12, 1);
        $this->travelTo('2026-10-21 11:30:00');
        $this->imported = asTenant($this->shop, fn () => Contract::query()->findOrFail($this->opened[0]));
        closeTake($this->imported, '50.00', by: $this->sara);
    });

    it('leaves the imported balances out of the day\'s money, count and other payments', function () {
        $totals = closeTotals($this->shop);

        expect($totals['expected_cash'])->toBe('150.00')
            ->and($totals['by_method'])->toBe(['cash' => '150.00', 'card' => '60.00'])
            ->and($totals['count'])->toBe(3)
            ->and(array_values($totals['per_member']))->toBe([['name' => 'Sara Collector', 'cash' => '150.00', 'count' => 2]])
            ->and(array_column($totals['non_cash'], 'amount'))->toBe(['60.00']);
    });

    it('closes the day with the sentence of what really came in', function () {
        expect(idtSentence($this, '150.00'))->toBe('Al-Fares, 21 Oct: collected SAR 210 (cash 150, other 60), 3 payments, difference 0.');
    });

    it('shows the contracts opened as one line worth a look', function () {
        $watch = closeTotals($this->shop)['watch'];

        // Twelve with a plan at 1,200.00 and one open account, which has no price.
        expect($watch)->toBe([['kind' => 'opened', 'id' => $this->opened[0], 'label' => '13 contracts opened', 'amount' => '14400.00']]);

        Sanctum::actingAs($this->sara, ['app']);
        expect($this->getJson('/api/v1/day-close/today')->assertOk()->json('data.watch'))->toBe($watch);
        $this->actingAs($this->sara)->get(route('app.day-close.show'))->assertOk()
            ->assertSeeText('13 contracts opened')->assertDontSeeText('Imported Customer 1');
    });

    it('keeps the balances on the contracts and in the reports', function () {
        asTenant($this->shop, function () {
            $balances = app(CustomerBalances::class)->forContracts($this->opened);
            $paid = Transaction::query()->where('note', 'Paid before Qistas')->get();

            // What each still owes: 1,200.00 less the 300.00 paid before (and the first one's 50.00 of today).
            expect($balances[$this->opened[0]])->toBe('850.00')
                ->and($balances[$this->opened[1]])->toBe('900.00')
                ->and($paid)->toHaveCount(12)
                ->and($paid->pluck('type')->unique()->all())->toBe(['payment'])
                ->and($paid->pluck('tag')->unique()->all())->toBe([Transaction::IMPORTED]);
        });

        // Every money-in line of the month still counts in the dashboard: 12 x 300.00 + 100.00 + 60.00 + 50.00.
        expect(app(DashboardMetrics::class)->for($this->shop)['collected_this_month'])->toBe('3810.00');
    });

    it('leaves a void of an imported balance out of the till of the day it is voided', function () {
        $this->travelTo('2026-10-22 06:00:00');
        $lump = asTenant($this->shop, fn () => Transaction::query()->where('contract_id', $this->opened[1])->where('tag', Transaction::IMPORTED)->sole());
        app(VoidTransaction::class)->handle($lump, 'Typed in the wrong row', $this->boss);
        closeTake($this->contract, '20.00', 'card', by: $this->sara);

        $totals = closeTotals($this->shop, '2026-10-22');

        // The balance never went through a till, so voiding it gives none back; the void itself is worth a look.
        expect($totals['by_method'])->toBe(['card' => '20.00'])
            ->and($totals['count'])->toBe(1)
            ->and(array_column($totals['non_cash'], 'amount'))->toBe(['20.00'])
            ->and(array_column($totals['watch'], 'kind'))->toBe(['void']);
    });
});

describe('contracts opened in a day', function () {
    it('are listed one by one up to ten', function () {
        $this->travelTo('2026-10-21 11:00:00');
        $opened = idtImport($this, 10);

        $watch = closeTotals($this->shop)['watch'];

        expect(array_column($watch, 'id'))->toBe($opened)
            ->and(array_unique(array_column($watch, 'kind')))->toBe(['opened'])
            ->and($watch[0]['label'])->toBe('Imported Customer 1');
    });

    it('fold into one line from the eleventh, the other kinds still listed', function () {
        $this->travelTo('2026-10-21 08:00:00');
        $void = closeTake($this->contract, '30.00', by: $this->sara);
        app(VoidTransaction::class)->handle($void, null, $this->boss);
        $this->travelTo('2026-10-21 11:00:00');
        $opened = idtImport($this, 11);

        $watch = closeTotals($this->shop)['watch'];

        expect(array_column($watch, 'kind'))->toBe(['void', 'opened'])
            ->and($watch[1])->toBe(['kind' => 'opened', 'id' => $opened[0], 'label' => '11 contracts opened', 'amount' => '13200.00']);
    });
});
