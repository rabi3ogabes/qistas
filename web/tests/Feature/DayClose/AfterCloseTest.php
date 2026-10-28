<?php

use App\Actions\RecordCustomerPayment;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\PaymentBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| Daily-habit wave A, H4, decision D10: once a day is closed, a change that touches it needs an owner or a manager and
| leaves an audit row. A change touches a closed day when it is a payment back-dated into it, or a void: a void touches
| the day its payment was taken and today, the day the reversal is recorded and the cash it moves. Taking money today is
| never refused, even after today was closed (the shop keeps selling after it counts the till); the audit trail notes it.
| Nothing in the ledger is edited.
|
| The workspace is in Riyadh (UTC+3). The 19th took one cash payment of 100.00 and was closed that evening; the clock
| stands at 15:00 on the 21st (12:00 UTC), and the 20th and the 21st are open unless a test closes them.
*/

/** [$by] voids [$payment]. Through the action: the screens let only owners and managers void at all, so the rule is tested where it lives. */
function voidAs(Transaction $payment, ?User $by, ?string $reason = null): Transaction
{
    return app(VoidTransaction::class)->handle($payment, $reason, $by);
}

/** What someone who may not change a closed day is told, on [$field]. */
function closedDayError(string $field): array
{
    return [$field => [__('That day is closed. Ask the owner or a manager.')]];
}

/** How many ledger lines [$contract] has. */
function ledgerLinesOf(Contract $contract): int
{
    return asTenant($contract->tenant, fn () => Transaction::query()->where('contract_id', $contract->id)->count());
}

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
    $this->shop = closeShop();
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->mona = closeStaff($this->shop, 'Mona Manager', 'manager');
    $this->contract = closeContract($this->shop);

    $this->travelTo('2026-10-19 09:00:00'); // noon in Riyadh
    $this->payment = closeTake($this->contract, '100.00', by: $this->sara);
    $this->travelTo('2026-10-19 17:00:00'); // 20:00 in Riyadh
    $this->closed = closeDay($this->shop, '2026-10-19', $this->sara, '100.00');

    $this->travelTo('2026-10-21 12:00:00');
});

test('a collector cannot record a payment dated on a closed day', function () {
    Sanctum::actingAs($this->sara, ['app']);

    // Typed as a date in the app, as the person does.
    $this->postJson("/api/v1/contracts/{$this->contract->id}/payments", ['amount' => '40.00', 'method' => 'cash', 'paid_at' => '2026-10-19'])
        ->assertUnprocessable()
        ->assertJsonPath('error.fields', closedDayError('paid_at'));
    // A moment of that day, straight to the action.
    expect(validationErrors(fn () => closeTake($this->contract, '40.00', by: $this->sara, at: '2026-10-19 12:00:00')))->toBe(closedDayError('paid_at'))
        ->and(ledgerLinesOf($this->contract))->toBe(1);

    // The day before today is open, so a payment back-dated to it is hers to record.
    $this->postJson("/api/v1/contracts/{$this->contract->id}/payments", ['amount' => '40.00', 'method' => 'cash', 'paid_at' => '2026-10-20'])->assertCreated();

    expect(ledgerLinesOf($this->contract))->toBe(2)
        ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);
});

test('an owner can, and an audit row is written', function () {
    Sanctum::actingAs($this->boss, ['app']);
    $this->postJson("/api/v1/contracts/{$this->contract->id}/payments", ['amount' => '40.00', 'method' => 'cash', 'paid_at' => '2026-10-19'])->assertCreated();
    $this->travelTo('2026-10-21 12:10:00');
    closeTake($this->contract, '10.00', by: $this->mona, at: '2026-10-19 12:00:00');
    $this->travelTo('2026-10-21 12:20:00');
    voidAs($this->payment, $this->boss, 'Entered on the wrong contract');

    $changed = fn (User $by, string $change) => [
        'action' => 'day_close.change_after_close', 'subject_id' => $this->closed->id, 'user_id' => $by->id, 'changes' => ['day' => '2026-10-19', 'change' => $change],
    ];
    expect(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([
        $changed($this->boss, 'payment'),
        $changed($this->mona, 'payment'),
        $changed($this->boss, 'void'),
    ])
        ->and(ledgerLinesOf($this->contract))->toBe(4)
        // The close is what was signed: it stays as it was, and reopening it is a separate, deliberate step.
        ->and($this->closed->fresh()->status)->toBe('closed')
        ->and($this->closed->fresh()->expected_cash)->toBe('100.0000');
});

test('a collector cannot void a payment from a closed day', function () {
    expect(validationErrors(fn () => voidAs($this->payment, $this->sara, 'Wrong customer')))->toBe(closedDayError('transaction'));

    // Today included: once today is closed, voiding a payment taken today changes the figures that were signed.
    $today = closeTake($this->contract, '30.00', by: $this->sara);
    $this->travelTo('2026-10-21 17:00:00');
    closeDay($this->shop, '2026-10-21', $this->sara, '30.00');

    expect(validationErrors(fn () => voidAs($today, $this->sara)))->toBe(closedDayError('transaction'))
        ->and(asTenant($this->shop, fn () => Transaction::query()->where('type', 'reversal')->count()))->toBe(0)
        ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);
});

test('voiding today\'s payment on an open day is unaffected', function () {
    $today = closeTake($this->contract, '30.00', by: $this->sara);
    $mistake = closeTake($this->contract, '15.00', by: $this->sara);

    $reversal = voidAs($today, $this->sara, 'Entered twice');
    voidAs($mistake, $this->boss);

    expect($reversal->type)->toBe('reversal')
        ->and(Money::cmp($reversal->amount, '-30.00'))->toBe(0)
        ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);
    expectConsistentLedger($this->contract);
});

test('early settlement and the across-contracts payment obey the same rule', function () {
    $customer = asTenant($this->shop, fn () => Customer::query()->findOrFail($this->contract->customer_id));
    $second = openContract($this->shop, ['customer_id' => $customer->id, 'principal' => '600.00', 'installment_count' => 3]);
    $settling = closeContract($this->shop, 'Noor Khan', ['principal' => '900.00', 'installment_count' => 3]);
    $onTheNineteenth = CarbonImmutable::parse('2026-10-19 12:00:00', 'UTC');
    $settle = fn (User $by) => app(RecordPayment::class)->settle($settling, 'none', '0', '900.00', 'cash', by: $by, paidAt: $onTheNineteenth);
    $acrossContracts = fn (User $by) => app(RecordCustomerPayment::class)->handle($customer, '600.00', 'cash', by: $by, paidAt: $onTheNineteenth);

    expect(validationErrors(fn () => $settle($this->sara)))->toBe(closedDayError('paid_at'))
        ->and(validationErrors(fn () => $acrossContracts($this->sara)))->toBe(closedDayError('paid_at'))
        ->and(asTenant($this->shop, fn () => PaymentBatch::query()->count()))->toBe(0)
        ->and(ledgerLinesOf($settling))->toBe(0)
        ->and(ledgerLinesOf($second))->toBe(0)
        ->and(ledgerLinesOf($this->contract))->toBe(1);

    // An owner may. Each is one change, so one audit row, however many ledger lines the payment across contracts writes.
    $settle($this->boss);
    $this->travelTo('2026-10-21 12:10:00');
    $batch = $acrossContracts($this->boss);

    expect(asTenant($this->shop, fn () => Transaction::query()->where('batch_id', $batch->id)->count()))->toBe(2)
        ->and(asTenant($this->shop, fn () => Contract::query()->findOrFail($settling->id)->status))->toBe('settled')
        ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe(array_fill(0, 2, [
            'action' => 'day_close.change_after_close', 'subject_id' => $this->closed->id, 'user_id' => $this->boss->id, 'changes' => ['day' => '2026-10-19', 'change' => 'payment'],
        ]));
});

test('reopening the day allows the change again', function () {
    reopenDay($this->closed, 'A payment was missed', $this->boss);

    $late = closeTake($this->contract, '40.00', by: $this->sara, at: '2026-10-19 12:00:00');
    voidAs($this->payment, $this->sara, 'Entered on the wrong day');

    expect($late->paid_at->toDateTimeString())->toBe('2026-10-19 12:00:00')
        ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);

    // Closed again, the day is guarded again.
    $this->travelTo('2026-10-21 12:10:00');
    closeDay($this->shop, '2026-10-19', $this->boss, '40.00');
    expect(validationErrors(fn () => voidAs($late, $this->sara)))->toBe(closedDayError('transaction'));
});

test('expectConsistentLedger still holds after an owner\'s after-close entry', function () {
    closeTake($this->contract, '250.00', by: $this->boss, at: '2026-10-19 12:00:00');
    voidAs($this->payment, $this->boss, 'Entered twice');
    $this->travelTo('2026-10-21 17:00:00');
    closeDay($this->shop, '2026-10-21', $this->boss);
    $this->travelTo('2026-10-21 17:30:00');
    closeTake($this->contract, '75.00', by: $this->sara);

    expectConsistentLedger($this->contract);
    // The close is a record of what was signed; the ledger is what happened, and the day's figures read it as it is now.
    expect($this->closed->fresh()->expected_cash)->toBe('100.0000')
        ->and(closeTotals($this->shop, '2026-10-19')['expected_cash'])->toBe('350.00')
        ->and(closeTotals($this->shop, '2026-10-21')['expected_cash'])->toBe('-25.00');
});

test('a collector can still take a payment after today was closed, and an audit row notes it', function () {
    $this->travelTo('2026-10-21 17:00:00'); // 20:00 in Riyadh
    $today = closeDay($this->shop, '2026-10-21', $this->sara);
    Sanctum::actingAs($this->sara, ['app']);

    $this->travelTo('2026-10-21 17:30:00'); // 20:30: a customer comes in after the count
    $this->postJson("/api/v1/contracts/{$this->contract->id}/payments", ['amount' => '25.00', 'method' => 'cash'])->assertCreated();
    $this->travelTo('2026-10-21 17:40:00');
    $this->postJson("/api/v1/contracts/{$this->contract->id}/payments", ['amount' => '15.00', 'method' => 'cash', 'paid_at' => '2026-10-21'])->assertCreated();
    // Back-dated to the open 20th: no closed day is touched, so there is nothing to note.
    $this->travelTo('2026-10-21 17:50:00');
    closeTake($this->contract, '5.00', by: $this->sara, at: '2026-10-20 12:00:00');

    $noted = ['action' => 'day_close.entry_after_close', 'subject_id' => $today->id, 'user_id' => $this->sara->id, 'changes' => ['day' => '2026-10-21']];
    expect(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([$noted, $noted])
        ->and(ledgerLinesOf($this->contract))->toBe(4);
});

test('a collector cannot void an older payment once today is closed, since the void lands in today', function () {
    $this->travelTo('2026-10-20 09:00:00');
    $tuesday = closeTake($this->contract, '50.00', by: $this->sara); // the 20th stays open
    $this->travelTo('2026-10-21 17:00:00');
    $today = closeDay($this->shop, '2026-10-21', $this->sara);

    expect(validationErrors(fn () => voidAs($tuesday, $this->sara)))->toBe(closedDayError('transaction'));

    // A manager may: the void moves the cash of the closed 21st, and the audit row says so.
    $this->travelTo('2026-10-21 17:10:00');
    voidAs($tuesday, $this->mona, 'Refunded');
    // A void of the 19th's payment touches two closed days, the 19th and today: each gets its row.
    $this->travelTo('2026-10-21 17:20:00');
    voidAs($this->payment, $this->boss, 'Refunded');

    expect(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([
        ['action' => 'day_close.change_after_close', 'subject_id' => $today->id, 'user_id' => $this->mona->id, 'changes' => ['day' => '2026-10-21', 'change' => 'void']],
        ['action' => 'day_close.change_after_close', 'subject_id' => $this->closed->id, 'user_id' => $this->boss->id, 'changes' => ['day' => '2026-10-19', 'change' => 'void']],
        ['action' => 'day_close.change_after_close', 'subject_id' => $today->id, 'user_id' => $this->boss->id, 'changes' => ['day' => '2026-10-21', 'change' => 'void']],
    ]);
});

it('guards nothing while the feature is off, and guards again once it is back on', function () {
    switchOff(Feature::DayClose);

    $late = closeTake($this->contract, '40.00', by: $this->sara, at: '2026-10-19 12:00:00');
    voidAs($this->payment, $this->sara);

    expect(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([])
        ->and($this->closed->fresh()->status)->toBe('closed');

    // The close was kept, so the rule is back with the feature.
    switchOn(Feature::DayClose);
    expect(validationErrors(fn () => voidAs($late, $this->sara)))->toBe(closedDayError('transaction'));
});

it('never lets another workspace\'s close guard this one', function () {
    $other = closeShop();
    $theirs = closeContract($other, 'Someone Else');
    $tariq = closeStaff($other, 'Tariq Collector');

    $payment = closeTake($theirs, '10.00', by: $tariq, at: '2026-10-19 12:00:00');
    voidAs($payment, $tariq);

    expect(ledgerLinesOf($theirs))->toBe(2)
        ->and(closeAudits($other->id))->toBe([])
        ->and(closeAudits($this->shop->id, 'day_close.%after_close'))->toBe([]);
});
