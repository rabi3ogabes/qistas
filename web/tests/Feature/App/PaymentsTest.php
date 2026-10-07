<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Format;
use App\Support\Money;

function paymentForm(array $overrides = []): array
{
    return array_merge(['amount' => '40.00', 'method' => 'cash', 'note' => '', 'idempotency_key' => 'form-'.uniqid()], $overrides);
}

/** The first payment line of the contract, as stored. */
function firstPaymentOf(Tenant $tenant, Contract $contract): Transaction
{
    return asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'payment')->orderBy('created_at')->firstOrFail());
}

function ledgerLines(Tenant $tenant, Contract $contract): int
{
    return asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->count());
}

beforeEach(fn () => $this->travelTo('2026-10-07 12:00:00'));

describe('recording a payment', function () {
    it('records it, applies it to the oldest instalment and returns to the contract', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), paymentForm(['amount' => '140.00', 'method' => 'bank_transfer', 'note' => 'Transfer 8841']))
            ->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('status');

        $payment = firstPaymentOf($tenant, $contract);
        $installments = installmentsOfContract($contract);
        expect(Money::cmp($payment->amount, '140'))->toBe(0)->and($payment->method)->toBe('bank_transfer')
            ->and($payment->note)->toBe('Transfer 8841')->and($payment->created_by_user_id)->toBe($user->id)
            ->and($installments[0]->status)->toBe('paid')->and($installments[1]->status)->toBe('partial')->and($installments[2]->status)->toBe('pending');
        expectConsistentLedger($contract);
    });

    it('settles the contract with the last payment', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), paymentForm(['amount' => '300']));

        expect(asTenant($tenant, fn () => Contract::find($contract->id)->status))->toBe('settled');
    });

    it('reads an amount typed on an Arabic keyboard', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), paymentForm(['amount' => '٤٠٫٥٠']));

        expect(Money::cmp(firstPaymentOf($tenant, $contract)->amount, '40.50'))->toBe(0);
    });

    it('dates the payment on the day it was received', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), paymentForm(['paid_at' => '2026-10-03']));

        expect(firstPaymentOf($tenant, $contract)->paid_at->toDateString())->toBe('2026-10-03');
    });

    it('treats today as now, not as midnight', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), paymentForm(['paid_at' => '2026-10-07']));

        expect(firstPaymentOf($tenant, $contract)->paid_at->format('Y-m-d H:i'))->toBe('2026-10-07 12:00');
    });

    it('explains what is wrong, recording nothing', function (array $changes, string $field) {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->from('/back')->post(route('app.contracts.payments.store', $contract), paymentForm($changes))
            ->assertRedirect('/back')->assertSessionHasErrors($field);
        expect(ledgerLines($tenant, $contract))->toBe(0);
    })->with([
        'no amount' => [['amount' => ''], 'amount'],
        'zero' => [['amount' => '0'], 'amount'],
        'three decimals' => [['amount' => '10.005'], 'amount'],
        'more than is owed' => [['amount' => '300.01'], 'amount'],
        'unknown method' => [['method' => 'bitcoin'], 'method'],
        'no method' => [['method' => ''], 'method'],
        'dated in the future' => [['paid_at' => '2026-10-09'], 'paid_at'],
        'not a date' => [['paid_at' => 'yesterday-ish'], 'paid_at'],
        'a bad key' => [['idempotency_key' => 'has spaces'], 'idempotency_key'],
        'an essay for a note' => [['note' => str_repeat('n', 1001)], 'note'],
    ]);

    it('is safe to submit twice: the same form key records one payment', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $form = paymentForm(['amount' => '100.00', 'idempotency_key' => 'form-abc-123']);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), $form)->assertRedirect(route('app.contracts.show', $contract));
        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), $form)->assertRedirect(route('app.contracts.show', $contract))->assertSessionHasNoErrors();

        expect(ledgerLines($tenant, $contract))->toBe(1);
    });

    it('will not let a key stand for a different payment', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), paymentForm(['amount' => '100.00', 'idempotency_key' => 'form-reused']));
        $this->actingAs($user)->from('/back')->post(route('app.contracts.payments.store', $contract), paymentForm(['amount' => '50.00', 'idempotency_key' => 'form-reused']))
            ->assertSessionHasErrors('idempotency_key');

        expect(ledgerLines($tenant, $contract))->toBe(1);
    });

    it('records without a form key too', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs($user)->post(route('app.contracts.payments.store', $contract), ['amount' => '10', 'method' => 'cash'])->assertSessionHasNoErrors();

        expect(ledgerLines($tenant, $contract))->toBe(1);
    });

    it('refuses a payment on a cancelled contract', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        asTenant($tenant, fn () => Contract::find($contract->id)->forceFill(['status' => 'cancelled'])->save());

        $this->actingAs($user)->from('/back')->post(route('app.contracts.payments.store', $contract), paymentForm())->assertSessionHasErrors('contract');
        expect(ledgerLines($tenant, $contract))->toBe(0);
    });

    it('is open to every role that may write, and closed to a viewer', function (string $role, int $status) {
        [, $tenant] = owner();
        $contract = openContract($tenant);

        $this->actingAs(memberAs($role, $tenant))->post(route('app.contracts.payments.store', $contract), paymentForm())->assertStatus($status);
        expect(ledgerLines($tenant, $contract))->toBe($status === 302 ? 1 : 0);
    })->with([['owner', 302], ['manager', 302], ['accountant', 302], ['collector', 302], ['viewer', 403]]);

    it('is not found for a contract of another workspace', function () {
        [$user] = owner();
        $theirs = openContract(Tenant::factory()->create());

        $this->actingAs($user)->post(route('app.contracts.payments.store', $theirs), paymentForm())->assertNotFound();
    });

    it('sends guests to sign in', function () {
        $contract = openContract(Tenant::factory()->create());

        $this->post(route('app.contracts.payments.store', $contract), paymentForm())->assertRedirect(route('login'));
    });

    it('prefills the form with what the next instalment still needs', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        app(RecordPayment::class)->handle($contract, '40.00', 'cash');

        $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertSee('name="amount"', false)->assertSee('value="60.00"', false);
    });
});

describe('voiding a payment', function () {
    it('reverses it, reopens the instalments and keeps the original', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '150.00', 'cash');

        $this->actingAs($user)->post(route('app.payments.void', $payment), ['reason' => 'Entered on the wrong contract'])
            ->assertRedirect(route('app.contracts.show', $contract))->assertSessionHas('status');

        $reversal = asTenant($tenant, fn () => Transaction::where('reverses_transaction_id', $payment->id)->sole());
        expect($reversal->type)->toBe('reversal')->and(Money::cmp($reversal->amount, '-150'))->toBe(0)
            ->and($reversal->note)->toBe('Entered on the wrong contract')->and($reversal->created_by_user_id)->toBe($user->id)
            ->and(ledgerLines($tenant, $contract))->toBe(2)
            ->and(installmentsOfContract($contract)->every(fn (Installment $i) => $i->status === 'pending'))->toBeTrue();
        expectConsistentLedger($contract);
    });

    it('reopens a contract that the payment had settled', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '300.00', 'cash');

        $this->actingAs($user)->post(route('app.payments.void', $payment));

        expect(asTenant($tenant, fn () => Contract::find($contract->id)->status))->toBe('active');
    });

    it('can be done once', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash');

        $this->actingAs($user)->post(route('app.payments.void', $payment));
        $this->actingAs($user)->from('/back')->post(route('app.payments.void', $payment))->assertSessionHasErrors('transaction');

        expect(ledgerLines($tenant, $contract))->toBe(2);
    });

    it('does not apply to a down payment', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant, ['down_payment' => '50.00']);
        $downPayment = asTenant($tenant, fn () => Transaction::where('contract_id', $contract->id)->where('type', 'down_payment')->sole());

        $this->actingAs($user)->from('/back')->post(route('app.payments.void', $downPayment))->assertSessionHasErrors('transaction');

        expect(ledgerLines($tenant, $contract))->toBe(1);
    });

    it('does not accept an over-long reason', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash');

        $this->actingAs($user)->from('/back')->post(route('app.payments.void', $payment), ['reason' => str_repeat('r', 501)])->assertSessionHasErrors('reason');

        expect(ledgerLines($tenant, $contract))->toBe(1);
    });

    it('is only for owners and managers', function (string $role, int $status) {
        [, $tenant] = owner();
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash');

        $this->actingAs(memberAs($role, $tenant))->post(route('app.payments.void', $payment))->assertStatus($status);
        expect(ledgerLines($tenant, $contract))->toBe($status === 302 ? 2 : 1);
    })->with([['owner', 302], ['manager', 302], ['accountant', 403], ['collector', 403], ['viewer', 403]]);

    it('is not found for a payment of another workspace', function () {
        [$user] = owner();
        $other = Tenant::factory()->create();
        $payment = app(RecordPayment::class)->handle(openContract($other), '100.00', 'cash');

        $this->actingAs($user)->post(route('app.payments.void', $payment))->assertNotFound();
    });

    it('is offered on the contract page for a payment that can still be voided', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant);
        $live = app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        $voided = app(RecordPayment::class)->handle($contract, '50.00', 'cash');
        app(VoidTransaction::class)->handle($voided);

        $page = $this->actingAs($user)->get(route('app.contracts.show', $contract))->assertOk();
        $page->assertSee(route('app.payments.void', $live), false)->assertDontSee(route('app.payments.void', $voided), false);
        $this->actingAs(memberAs('collector', $tenant))->get(route('app.contracts.show', $contract))->assertDontSee(route('app.payments.void', $live), false);
    });

    it('shows a voided payment and its reversal in the contract’s history', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        $payment = app(RecordPayment::class)->handle($contract, '100.00', 'cash');
        app(VoidTransaction::class)->handle($payment, 'Wrong contract');

        $this->actingAs($user)->get(route('app.contracts.show', $contract))
            ->assertSee('data-line="voided"', false)->assertSee('data-line="reversal"', false)->assertSee('Wrong contract');
    });
});

describe('the payments page', function () {
    it('sends guests to sign in', function () {
        $this->get('/app/payments')->assertRedirect(route('login'));
    });

    it('lists the workspace’s ledger, newest first, with customer, contract, method and who took it', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $first = openContract($tenant);
        $second = openContract($tenant);
        $clerk = memberAs('collector', $tenant);
        $clerk->forceFill(['name' => 'Karim Collector'])->save();
        $this->travelTo('2026-10-08 12:00:00');
        app(RecordPayment::class)->handle($first, '40.00', 'bank_transfer', by: $clerk);
        $this->travelTo('2026-10-09 12:00:00');
        app(RecordPayment::class)->handle($second, '25.00', 'cash', by: $user);
        $foreign = openContract(Tenant::factory()->create());
        app(RecordPayment::class)->handle($foreign, '77.00', 'cash');

        $this->actingAs($user)->get('/app/payments')->assertOk()
            ->assertSeeInOrder([$second->reference(), Format::money('25.00', 'SAR'), $first->reference(), Format::money('40.00', 'SAR')])
            ->assertSee('Karim Collector')->assertSee('Bank transfer')->assertSee(route('app.contracts.show', $first), false)
            ->assertDontSee(Format::money('77.00', 'SAR'));
    });

    it('shows reversals as negative lines', function () {
        [$user, $tenant] = owner(['currency' => 'SAR']);
        $contract = openContract($tenant);
        app(VoidTransaction::class)->handle(app(RecordPayment::class)->handle($contract, '100.00', 'cash'));

        $this->actingAs($user)->get('/app/payments')->assertSee('data-line="reversal"', false)->assertSee(Format::money('-100.00', 'SAR'));
    });

    it('invites you to record the first payment', function () {
        [$user] = owner();

        $this->actingAs($user)->get('/app/payments')->assertSee('No payments yet');
    });

    it('is open to a viewer', function () {
        [, $tenant] = owner();

        $this->actingAs(memberAs('viewer', $tenant))->get('/app/payments')->assertOk();
    });

    it('pages through a long ledger', function () {
        [$user, $tenant] = owner();
        $contract = openContract($tenant, ['principal' => '1000.00', 'installment_count' => 3]);
        foreach (range(1, 30) as $i) {
            $this->travelTo(sprintf('2026-10-07 12:00:%02d', $i));
            app(RecordPayment::class)->handle($contract, '1.00', 'cash', note: sprintf('n%03d', $i));
        }

        $this->actingAs($user)->get('/app/payments')->assertSee('n030')->assertSee('n006')->assertDontSee('n005')->assertSee('page=2', false);
    });
});
