<?php

use App\Actions\RecordPayment;
use App\Models\Contract;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;

function pay(Contract $contract, string $amount, string $method = 'cash', ?string $key = null, array $extra = []): Transaction
{
    return app(RecordPayment::class)->handle(
        $contract, $amount, $method, $key,
        by: $extra['by'] ?? null, note: $extra['note'] ?? null, paidAt: $extra['paid_at'] ?? null,
    );
}

function transactionsOf(Contract $contract): int
{
    return asTenant($contract->tenant, fn () => Transaction::where('contract_id', $contract->id)->count());
}

beforeEach(function () {
    $this->tenant = workspaceOn();
    $this->contract = openContract($this->tenant);
});

describe('applying a payment', function () {
    it('pays the oldest instalment first and marks a part-paid one as partial', function () {
        $transaction = pay($this->contract, '150.00');

        $installments = installmentsOfContract($this->contract);
        expect($transaction->type)->toBe('payment')
            ->and(Money::cmp($transaction->amount, '150.00'))->toBe(0)
            ->and($installments[0]->status)->toBe('paid')
            ->and(Money::cmp($installments[0]->paid_amount, '100.00'))->toBe(0)
            ->and($installments[0]->paid_at)->not->toBeNull()
            ->and($installments[1]->status)->toBe('partial')
            ->and(Money::cmp($installments[1]->paid_amount, '50.00'))->toBe(0)
            ->and($installments[1]->paid_at)->toBeNull()
            ->and($installments[2]->status)->toBe('pending');
        expect(asTenant($this->tenant, fn () => Contract::find($this->contract->id)->status))->toBe('active');
        expectConsistentLedger($this->contract);
    });

    it('records exactly which instalments a payment settled', function () {
        $transaction = pay($this->contract, '150.00');

        $allocations = asTenant($this->tenant, fn () => TransactionAllocation::where('transaction_id', $transaction->id)->orderBy('amount', 'desc')->get());
        expect($allocations)->toHaveCount(2)
            ->and(Money::cmp($allocations[0]->amount, '100.00'))->toBe(0)
            ->and(Money::cmp($allocations[1]->amount, '50.00'))->toBe(0);
    });

    it('settles the contract when the last unit is paid', function () {
        pay($this->contract, '150.00');
        $this->travelTo(now()->addDay());
        pay($this->contract, '150.00');

        $contract = asTenant($this->tenant, fn () => Contract::find($this->contract->id));
        expect($contract->status)->toBe('settled')->and($contract->settled_at)->not->toBeNull()
            ->and(installmentsOfContract($this->contract)->every(fn ($i) => $i->status === 'paid'))->toBeTrue();
        expectConsistentLedger($this->contract);
    });

    it('can settle everything in one payment', function () {
        pay($this->contract, '300.00');

        expect(asTenant($this->tenant, fn () => Contract::find($this->contract->id)->status))->toBe('settled');
    });

    it('stays consistent through many odd payments', function () {
        foreach (['33.33', '0.01', '66.66', '100.00', '100.00'] as $amount) {
            pay($this->contract, $amount);
            expectConsistentLedger($this->contract);
        }

        expect(asTenant($this->tenant, fn () => Contract::find($this->contract->id)->status))->toBe('settled');
    });

    it('records who took the payment, how, when and why', function () {
        $user = User::factory()->create();
        $when = now()->subDays(2)->startOfSecond();

        $transaction = pay($this->contract, '40.00', 'bank_transfer', null, ['by' => $user, 'note' => 'Transfer ref 8841', 'paid_at' => $when]);

        expect($transaction->created_by_user_id)->toBe($user->id)->and($transaction->method)->toBe('bank_transfer')
            ->and($transaction->note)->toBe('Transfer ref 8841')->and($transaction->paid_at->equalTo($when))->toBeTrue()
            ->and($transaction->customer_id)->toBe($this->contract->customer_id)
            ->and($transaction->tenant_id)->toBe($this->tenant->id);
    });

    it('dates a payment now when no date is given', function () {
        $this->travelTo('2026-05-05 10:00:00');

        expect(pay($this->contract, '10.00')->paid_at->format('Y-m-d H:i'))->toBe('2026-05-05 10:00');
    });

    it('does not leave the workspace switched on afterwards', function () {
        pay($this->contract, '10.00');

        expect(app(CurrentTenant::class)->get())->toBeNull();
    });
});

describe('payments that are refused', function () {
    it('rejects an amount that makes no sense, naming the field and writing nothing', function (string $amount) {
        $errors = validationErrors(fn () => pay($this->contract, $amount));

        expect($errors)->toHaveKey('amount')
            ->and(transactionsOf($this->contract))->toBe(0)
            ->and(installmentsOfContract($this->contract)->every(fn ($i) => Money::isZero($i->paid_amount)))->toBeTrue();
    })->with([
        'zero' => '0',
        'zero with decimals' => '0.00',
        'negative' => '-5.00',
        'five decimals' => '10.12345',
        'three decimals' => '10.123',
        'not a number' => 'ten',
        'empty' => '',
        'scientific notation' => '1e2',
        'more than is owed' => '300.01',
        'far more than is owed' => '100000.00',
    ]);

    it('rejects paying more than what is still owed after earlier payments', function () {
        pay($this->contract, '250.00');

        expect(validationErrors(fn () => pay($this->contract, '50.01')))->toHaveKey('amount');
        pay($this->contract, '50.00');
    });

    it('tells the person how much is still owed when they pay too much', function () {
        pay($this->contract, '250.00');

        $errors = validationErrors(fn () => pay($this->contract, '75.00'));

        expect($errors['amount'][0])->toContain('50.00');
    });

    it('rejects a payment on a settled contract', function () {
        pay($this->contract, '300.00');

        expect(validationErrors(fn () => pay($this->contract, '1.00')))->toHaveKey('amount');
    });

    it('rejects a payment on a cancelled contract', function () {
        asTenant($this->tenant, fn () => Contract::find($this->contract->id)->forceFill(['status' => 'cancelled'])->save());

        expect(validationErrors(fn () => pay($this->contract, '10.00')))->toHaveKey('contract')
            ->and(transactionsOf($this->contract))->toBe(0);
    });

    it('rejects an unknown payment method', function () {
        expect(validationErrors(fn () => pay($this->contract, '10.00', 'bitcoin')))->toHaveKey('method');
    });

    it('rejects a payment dated in the future', function () {
        expect(validationErrors(fn () => pay($this->contract, '10.00', 'cash', null, ['paid_at' => now()->addDay()])))->toHaveKey('paid_at');
    });

    it('rejects a malformed idempotency key', function (string $key) {
        expect(validationErrors(fn () => pay($this->contract, '10.00', 'cash', $key)))->toHaveKey('idempotency_key');
    })->with(['too long' => [str_repeat('k', 101)], 'spaces' => ['two words'], 'markup' => ['<script>']]);
});

describe('retrying safely', function () {
    it('records a payment once however many times the same key is sent', function () {
        $first = pay($this->contract, '100.00', 'cash', 'req-123');
        $second = pay($this->contract, '100.00', 'cash', 'req-123');
        $third = pay($this->contract, '100.00', 'cash', 'req-123');

        expect($second->id)->toBe($first->id)->and($third->id)->toBe($first->id)
            ->and($first->wasRecentlyCreated)->toBeTrue()->and($second->wasRecentlyCreated)->toBeFalse()
            ->and(transactionsOf($this->contract))->toBe(1)
            ->and(Money::cmp(installmentsOfContract($this->contract)[0]->paid_amount, '100.00'))->toBe(0);
        expectConsistentLedger($this->contract);
    });

    it('treats a different amount under the same key as a mistake, not a retry', function () {
        pay($this->contract, '100.00', 'cash', 'req-123');

        expect(validationErrors(fn () => pay($this->contract, '50.00', 'cash', 'req-123')))->toHaveKey('idempotency_key')
            ->and(transactionsOf($this->contract))->toBe(1);
    });

    it('treats the same key on another contract as a mistake too', function () {
        pay($this->contract, '100.00', 'cash', 'req-123');
        $other = openContract($this->tenant);

        expect(validationErrors(fn () => pay($other, '100.00', 'cash', 'req-123')))->toHaveKey('idempotency_key');
    });

    it('lets two workspaces use the same key', function () {
        $elsewhere = workspaceOn();
        $theirs = openContract($elsewhere);

        pay($this->contract, '100.00', 'cash', 'req-123');
        pay($theirs, '100.00', 'cash', 'req-123');

        expect(transactionsOf($theirs))->toBe(1);
    });

    it('still lets a payment go through twice without a key', function () {
        pay($this->contract, '100.00');
        pay($this->contract, '100.00');

        expect(transactionsOf($this->contract))->toBe(2);
    });
});

it('only ever touches the contract’s own workspace', function () {
    $mine = workspaceOn();
    $theirs = workspaceOn();
    $theirContract = openContract($theirs);

    pay($theirContract, '100.00');

    expect(transactionsOf($theirContract))->toBe(1)
        ->and(asTenant($mine, fn () => Transaction::count()))->toBe(0);
});
