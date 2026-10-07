<?php

use App\Domain\Ledger\PaymentAllocator;
use App\Support\Money;

/** @return array{id: string, number: int, due_date: string, remaining: string} */
function due(string $id, int $number, string $date, string $remaining): array
{
    return ['id' => $id, 'number' => $number, 'due_date' => $date, 'remaining' => $remaining];
}

function allocate(array $installments, string $amount): array
{
    return (new PaymentAllocator)->allocate($installments, $amount);
}

it('pays the oldest due instalment first', function () {
    $result = allocate([
        due('b', 2, '2026-03-01', '100.00'),
        due('a', 1, '2026-02-01', '100.00'),
    ], '60.00');

    expect($result)->toBe([['installment_id' => 'a', 'amount' => '60.00']]);
});

it('settles an instalment completely before touching the next', function () {
    $result = allocate([
        due('a', 1, '2026-02-01', '100.00'),
        due('b', 2, '2026-03-01', '100.00'),
        due('c', 3, '2026-04-01', '100.00'),
    ], '250.00');

    expect($result)->toBe([
        ['installment_id' => 'a', 'amount' => '100.00'],
        ['installment_id' => 'b', 'amount' => '100.00'],
        ['installment_id' => 'c', 'amount' => '50.00'],
    ]);
});

it('tops up a part-paid instalment first, using only what it still needs', function () {
    $result = allocate([
        due('a', 1, '2026-02-01', '40.00'),
        due('b', 2, '2026-03-01', '100.00'),
    ], '70.00');

    expect($result)->toBe([
        ['installment_id' => 'a', 'amount' => '40.00'],
        ['installment_id' => 'b', 'amount' => '30.00'],
    ]);
});

it('skips instalments that are already paid', function () {
    $result = allocate([
        due('a', 1, '2026-02-01', '0.00'),
        due('b', 2, '2026-03-01', '100.00'),
    ], '50.00');

    expect($result)->toBe([['installment_id' => 'b', 'amount' => '50.00']]);
});

it('breaks ties on the due date by instalment number', function () {
    $result = allocate([
        due('second', 2, '2026-02-01', '10.00'),
        due('first', 1, '2026-02-01', '10.00'),
    ], '10.00');

    expect($result)->toBe([['installment_id' => 'first', 'amount' => '10.00']]);
});

it('keeps odd cents exact', function () {
    $result = allocate([
        due('a', 1, '2026-02-01', '33.33'),
        due('b', 2, '2026-03-01', '33.33'),
        due('c', 3, '2026-04-01', '33.34'),
    ], '50.00');

    expect($result)->toBe([
        ['installment_id' => 'a', 'amount' => '33.33'],
        ['installment_id' => 'b', 'amount' => '16.67'],
    ]);
});

it('can pay everything that is owed, exactly', function () {
    $result = allocate([
        due('a', 1, '2026-02-01', '33.33'),
        due('b', 2, '2026-03-01', '33.33'),
        due('c', 3, '2026-04-01', '33.34'),
    ], '100.00');

    expect(array_column($result, 'amount'))->toBe(['33.33', '33.33', '33.34']);
});

it('refuses to allocate more than is owed', function () {
    allocate([due('a', 1, '2026-02-01', '100.00')], '100.01');
})->throws(InvalidArgumentException::class);

it('refuses a payment of nothing, or less', function (string $amount) {
    allocate([due('a', 1, '2026-02-01', '100.00')], $amount);
})->with(['0.00', '0', '-5.00'])->throws(InvalidArgumentException::class);

it('refuses a payment with nothing left to pay', function () {
    allocate([due('a', 1, '2026-02-01', '0.00')], '1.00');
})->throws(InvalidArgumentException::class);

it('always allocates exactly the amount paid, whatever the schedule', function () {
    mt_srand(20261007);

    for ($run = 0; $run < 200; $run++) {
        $count = mt_rand(1, 24);
        $installments = [];
        $owed = '0';
        for ($n = 1; $n <= $count; $n++) {
            $remaining = sprintf('%d.%02d', mt_rand(0, 900), mt_rand(0, 99));
            $installments[] = due("i{$n}", $n, sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28)), $remaining);
            $owed = Money::add($owed, $remaining);
        }
        if (Money::isZero($owed)) {
            continue;
        }
        $cents = (int) bcmul($owed, '100', 0);
        $amount = bcdiv((string) mt_rand(1, $cents), '100', 2);

        $result = allocate($installments, $amount);

        $sum = array_reduce($result, fn (string $carry, array $row) => Money::add($carry, $row['amount']), '0');
        expect(Money::cmp($sum, $amount))->toBe(0);
        $byId = array_column($installments, 'remaining', 'id');
        foreach ($result as $row) {
            expect(Money::cmp($row['amount'], $byId[$row['installment_id']]))->toBeLessThanOrEqual(0)
                ->and(Money::isPositive($row['amount']))->toBeTrue();
        }
    }
});
