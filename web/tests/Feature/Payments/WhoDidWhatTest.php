<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\TransactionAllocation;

/*
 * Win Plan PP16: on every line, who recorded it and when; on a voided payment, who voided it and why; and before a
 * payment is taken, exactly what it will cover, worked out by the same allocator and never written.
 */

beforeEach(fn () => $this->travelTo('2026-10-10 14:32:00'));

describe('every line says who did it', function () {
    it('names who recorded a payment and when, and who voided it and why', function () {
        [$owner, $tenant] = apiOwner();
        $contract = openContract($tenant);
        $sara = memberAs('collector', $tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash', by: $sara);
        app(VoidTransaction::class)->handle($payment, 'Entered twice', $owner);

        $lines = collect($this->getJson("/api/v1/contracts/{$contract->id}")->assertOk()->json('data.transactions'));
        $paid = $lines->firstWhere('type', 'payment');
        $reversal = $lines->firstWhere('type', 'reversal');

        expect($paid['recorded_by'])->toBe(['id' => $sara->id, 'name' => $sara->name])
            ->and($paid['recorded_at'])->toBe('2026-10-10T14:32:00Z')
            ->and($paid['voided'])->toBeTrue()
            ->and($paid['reversal'])->toMatchArray(['by' => ['id' => $owner->id, 'name' => $owner->name], 'reason' => 'Entered twice'])
            ->and($reversal['recorded_by']['id'])->toBe($owner->id)
            ->and($reversal['note'])->toBe('Entered twice');
    });

    it('says the same on the payments list', function () {
        [$owner, $tenant] = apiOwner();
        $payment = app(RecordPayment::class)->handle(openContract($tenant), '100.00', 'cash', by: $owner);
        app(VoidTransaction::class)->handle($payment, 'Wrong contract', $owner);

        $line = collect($this->getJson('/api/v1/payments')->assertOk()->json('data'))->firstWhere('type', 'payment');

        expect($line['recorded_by']['name'])->toBe($owner->name)->and($line['reversal']['reason'])->toBe('Wrong contract');
    });

    it('shows who recorded and who voided on the web contract page', function () {
        [$owner, $tenant] = owner();
        $contract = openContract($tenant);
        $sara = memberAs('collector', $tenant);
        $sara->forceFill(['name' => 'Sara Hamdan'])->save();
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash', by: $sara);
        app(VoidTransaction::class)->handle($payment, 'Entered twice', $owner);

        $this->actingAs($owner)->get(route('app.contracts.show', $contract))->assertOk()
            ->assertSee('Recorded by Sara Hamdan')->assertSee('Voided by '.$owner->name)->assertSee('Entered twice');
    });
});

describe('what a payment will cover', function () {
    it('shows the instalments it pays in full and in part, and what is left on the last one', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant); // three instalments of 100

        $this->postJson("/api/v1/contracts/{$contract->id}/payments/preview", ['amount' => '160'])->assertOk()
            ->assertJsonPath('data.covers', [
                ['number' => 1, 'due_date' => '2026-02-01', 'amount' => '100.00', 'settles' => true],
                ['number' => 2, 'due_date' => '2026-03-01', 'amount' => '60.00', 'settles' => false],
            ])
            ->assertJsonPath('data.left_on_last', '40.00')
            ->assertJsonPath('data.owed_after', '140.00');
    });

    it('writes nothing, and recording the same amount then does exactly what it said', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $preview = $this->postJson("/api/v1/contracts/{$contract->id}/payments/preview", ['amount' => '250'])->assertOk()->json('data.covers');
        expect(asTenant($tenant, fn () => Transaction::query()->count()))->toBe(0)
            ->and(installmentsOfContract($contract)->pluck('paid_amount')->map(fn ($v) => (float) $v)->all())->toBe([0.0, 0.0, 0.0]);

        $payment = app(RecordPayment::class)->handle($contract, '250.00', 'cash');
        $done = asTenant($tenant, fn () => TransactionAllocation::query()->where('transaction_id', $payment->id)->get()
            ->map(fn (TransactionAllocation $a) => ['number' => Installment::query()->find($a->installment_id)->number, 'amount' => number_format((float) $a->amount, 2, '.', '')])
            ->sortBy('number')->values()->all());

        expect(collect($preview)->map(fn (array $c) => ['number' => $c['number'], 'amount' => $c['amount']])->all())->toBe($done);
    });

    it('refuses more than is owed, as recording would', function () {
        [, $tenant] = apiOwner();
        $contract = openContract($tenant);

        $this->postJson("/api/v1/contracts/{$contract->id}/payments/preview", ['amount' => '301'])->assertStatus(422);
    });
});
