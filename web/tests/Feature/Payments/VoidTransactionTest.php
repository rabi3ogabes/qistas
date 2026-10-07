<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;

function recordPayment(Contract $contract, string $amount, string $method = 'cash'): Transaction
{
    return app(RecordPayment::class)->handle($contract, $amount, $method);
}

function voidIt(Transaction $transaction, ?string $reason = null, ?User $by = null): Transaction
{
    return app(VoidTransaction::class)->handle($transaction, $reason, $by);
}

beforeEach(function () {
    $this->tenant = workspaceOn();
    $this->contract = openContract($this->tenant);
});

it('cancels a payment with a reversal and re-opens what it paid', function () {
    $payment = recordPayment($this->contract, '150.00');

    $reversal = voidIt($payment, 'Entered on the wrong customer');

    $installments = installmentsOfContract($this->contract);
    expect($reversal->type)->toBe('reversal')
        ->and(Money::cmp($reversal->amount, '-150.00'))->toBe(0)
        ->and($reversal->reverses_transaction_id)->toBe($payment->id)
        ->and($reversal->note)->toBe('Entered on the wrong customer')
        ->and($reversal->contract_id)->toBe($payment->contract_id)
        ->and($installments->every(fn ($i) => $i->status === 'pending' && Money::isZero($i->paid_amount) && $i->paid_at === null))->toBeTrue();
    expectConsistentLedger($this->contract);
});

it('leaves the original payment exactly as it was', function () {
    $payment = recordPayment($this->contract, '150.00');
    voidIt($payment);

    $stored = asTenant($this->tenant, fn () => Transaction::find($payment->id));
    expect($stored->type)->toBe('payment')->and(Money::cmp($stored->amount, '150.00'))->toBe(0);
});

it('re-opens a settled contract', function () {
    $payment = recordPayment($this->contract, '300.00');
    expect(asTenant($this->tenant, fn () => Contract::find($this->contract->id)->status))->toBe('settled');

    voidIt($payment);

    $contract = asTenant($this->tenant, fn () => Contract::find($this->contract->id));
    expect($contract->status)->toBe('active')->and($contract->settled_at)->toBeNull();
    expectConsistentLedger($this->contract);
});

it('undoes only that payment when later ones exist', function () {
    $first = recordPayment($this->contract, '100.00');
    recordPayment($this->contract, '100.00');

    voidIt($first);

    $installments = installmentsOfContract($this->contract);
    expect($installments[0]->status)->toBe('pending')
        ->and($installments[1]->status)->toBe('paid')
        ->and($installments[2]->status)->toBe('pending');
    expectConsistentLedger($this->contract);
});

it('lets the money be taken again after a void', function () {
    voidIt(recordPayment($this->contract, '300.00'));

    recordPayment($this->contract, '300.00');

    expect(asTenant($this->tenant, fn () => Contract::find($this->contract->id)->status))->toBe('settled');
    expectConsistentLedger($this->contract);
});

it('records who voided it, mirroring the allocations', function () {
    $user = User::factory()->create();
    $payment = recordPayment($this->contract, '150.00');

    $reversal = voidIt($payment, null, $user);

    $net = asTenant($this->tenant, fn () => TransactionAllocation::whereIn('transaction_id', [$payment->id, $reversal->id])->get()
        ->reduce(fn (string $c, $a) => Money::add($c, $a->amount), '0'));
    expect($reversal->created_by_user_id)->toBe($user->id)->and(Money::isZero($net))->toBeTrue();
});

it('refuses to void the same payment twice', function () {
    $payment = recordPayment($this->contract, '100.00');
    voidIt($payment);

    expect(validationErrors(fn () => voidIt($payment)))->toHaveKey('transaction');
});

it('refuses to void a reversal', function () {
    $reversal = voidIt(recordPayment($this->contract, '100.00'));

    expect(validationErrors(fn () => voidIt($reversal)))->toHaveKey('transaction');
});

it('does not void a down payment, which is a term of the contract itself', function () {
    $contract = openContract($this->tenant, ['principal' => '400.00', 'down_payment' => '100.00']);
    $down = asTenant($this->tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'down_payment')->sole());

    expect(validationErrors(fn () => voidIt($down)))->toHaveKey('transaction');
    expect(asTenant($this->tenant, fn () => Transaction::where('contract_id', $contract->id)->count()))->toBe(1);
    expectConsistentLedger($contract);
});

it('keeps the ledger consistent through a long mix of payments and voids', function () {
    $payments = [];
    foreach (['40.00', '60.00', '75.50', '24.50', '10.00'] as $amount) {
        $payments[] = recordPayment($this->contract, $amount);
        expectConsistentLedger($this->contract);
    }

    foreach ([1, 3] as $index) {
        voidIt($payments[$index]);
        expectConsistentLedger($this->contract);
    }
});
