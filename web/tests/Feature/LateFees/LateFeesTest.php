<?php

use App\Actions\CancelContract;
use App\Actions\ChargeLateFees;
use App\Actions\ConvertToOpen;
use App\Actions\RecordCustomerPayment;
use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Actions\WaiveLateFee;
use App\Domain\LateFees\LateFees;
use App\Domain\Ledger\Statement;
use App\Domain\Settlement\EarlySettlementQuote;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\InvestorEntry;
use App\Models\LateFee;
use App\Models\LateFeeAllocation;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Settings\TenantSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/*
| Late fees (Win Plan K4, CT20). A fee is its own line, never added to an instalment. The workspace's policy is copied
| into each contract when it is made; the nightly job charges each period's fee once, after the grace days, within the
| caps. A payment pays what is late first, then the fees, then what is not yet due. A void puts fees back exactly; a
| waiver needs a reason. Fees are the shop's (or charity's), never an investor's.
|
| The contract (openContract): three monthly instalments of 100.00 due 1 Feb, 1 Mar and 1 Apr 2026, no markup. The
| workspace is in Saudi Arabia, so its day is Riyadh's (UTC+3): noon UTC is the same day there.
*/

beforeEach(function () {
    $this->travelTo('2026-01-15 09:00:00');
    switchOn(Feature::FlexibleSchedules);
});

/** Switches late fees on and sets the workspace's policy (fixed 10.00, once, unless told otherwise). */
function feePolicy(Tenant $tenant, array $policy = []): void
{
    switchOn(Feature::LateFees);
    foreach (array_merge(['type' => 'fixed', 'amount' => '10', 'frequency' => 'once'], $policy) as $key => $value) {
        TenantSettings::for($tenant)->set("late_fees.{$key}", $value);
    }
}

function chargeFees(Tenant $tenant): int
{
    return app(ChargeLateFees::class)->handle(Tenant::find($tenant->id));
}

/** @return Collection<int, LateFee> */
function feesOf(Contract $contract): Collection
{
    return asTenant($contract->tenant, fn () => LateFee::with('installment')->where('contract_id', $contract->id)->orderBy('charged_on')->orderBy('period')->get());
}

function feeAmounts(Contract $contract): array
{
    return feesOf($contract)->map(fn (LateFee $fee) => Money::add($fee->amount, '0', 2))->all();
}

function payFee(Contract $contract, string $amount, ?string $applyTo = null): Transaction
{
    return app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), $amount, 'cash', applyTo: $applyTo);
}

/** The contract the fee tests use: grace days as given, made after the policy is in place. */
function lateContract(Tenant $tenant, int $graceDays = 0): Contract
{
    return openContract($tenant, ['grace_days' => $graceDays]);
}

function freshContract(Contract $contract): Contract
{
    return Contract::withoutGlobalScopes()->find($contract->id);
}

describe('charging', function () {
    it('charges nothing within the grace days, and the first fee the day after them', function () {
        [, $tenant] = owner();
        feePolicy($tenant);
        $contract = lateContract($tenant, graceDays: 3);

        $this->travelTo('2026-02-04 12:00:00');
        expect(chargeFees($tenant))->toBe(0);

        $this->travelTo('2026-02-05 12:00:00');
        expect(chargeFees($tenant))->toBe(1);

        $fee = feesOf($contract)->sole();
        expect($fee->installment->number)->toBe(1)
            ->and($fee->period)->toBe(1)
            ->and($fee->charged_on->format('Y-m-d'))->toBe('2026-02-05')
            ->and(Money::add($fee->amount, '0', 2))->toBe('10.00')
            ->and($fee->status)->toBe('open');
    });

    it('goes by the workspace’s own day, not UTC', function () {
        [, $tenant] = owner();
        feePolicy($tenant);
        $contract = lateContract($tenant);

        // 22:00 UTC on 1 Feb is already 2 Feb in Riyadh, the first day the instalment is late.
        $this->travelTo('2026-02-01 22:00:00');
        chargeFees($tenant);

        expect(feesOf($contract))->toHaveCount(1);
    });

    it('charges a once fee only once per instalment', function () {
        [, $tenant] = owner();
        feePolicy($tenant);
        $contract = lateContract($tenant);

        $this->travelTo('2026-03-20 12:00:00');
        chargeFees($tenant);

        expect(feesOf($contract)->map(fn (LateFee $f) => [$f->installment->number, $f->period])->all())->toBe([[1, 1], [2, 1]]);
    });

    it('charges a daily fee for each day late', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['amount' => '5', 'frequency' => 'daily']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-02-04 12:00:00');
        chargeFees($tenant);

        expect(feesOf($contract)->map(fn (LateFee $f) => $f->charged_on->format('Y-m-d'))->all())->toBe(['2026-02-02', '2026-02-03', '2026-02-04'])
            ->and(feeAmounts($contract))->toBe(['5.00', '5.00', '5.00']);
    });

    it('charges a weekly fee as each week late begins', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'weekly']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-02-15 12:00:00');
        chargeFees($tenant);
        expect(feesOf($contract)->map(fn (LateFee $f) => $f->charged_on->format('Y-m-d'))->all())->toBe(['2026-02-02', '2026-02-09']);

        $this->travelTo('2026-02-16 12:00:00');
        chargeFees($tenant);
        expect(feesOf($contract)->map(fn (LateFee $f) => $f->period)->all())->toBe([1, 2, 3]);
    });

    it('charges a monthly fee as each month late begins', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'monthly']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-03-01 12:00:00');
        chargeFees($tenant);
        expect(feesOf($contract)->map(fn (LateFee $f) => $f->charged_on->format('Y-m-d'))->all())->toBe(['2026-02-02']);

        $this->travelTo('2026-03-02 12:00:00');
        chargeFees($tenant);
        $first = feesOf($contract)->filter(fn (LateFee $f) => $f->installment->number === 1);
        expect($first->map(fn (LateFee $f) => $f->charged_on->format('Y-m-d'))->values()->all())->toBe(['2026-02-02', '2026-03-02']);
    });

    it('charges a percent of what is still owed on the instalment, rounded to the cent', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['type' => 'percent', 'amount' => '2.5']);
        $contract = lateContract($tenant);
        payFee($contract, '40.00');

        $this->travelTo('2026-02-02 12:00:00');
        chargeFees($tenant);

        // 2.5% of the 60.00 still owed.
        expect(feeAmounts($contract))->toBe(['1.50']);
    });

    it('stops at the cap per instalment, clipping the last fee', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'daily', 'cap_installment' => '25']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-02-10 12:00:00');
        chargeFees($tenant);

        expect(feeAmounts($contract))->toBe(['10.00', '10.00', '5.00']);
    });

    it('stops at the cap per contract, oldest fees first', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'daily', 'cap_contract' => '35']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-03-04 12:00:00');
        chargeFees($tenant);

        expect(feeAmounts($contract))->toBe(['10.00', '10.00', '10.00', '5.00'])
            ->and(feesOf($contract)->every(fn (LateFee $f) => $f->installment->number === 1))->toBeTrue();
    });

    it('counts a waived fee towards the cap, so a waiver is never charged again', function () {
        [$owner, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'daily', 'cap_installment' => '20']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-02-03 12:00:00');
        chargeFees($tenant);
        app(WaiveLateFee::class)->handle(feesOf($contract)->first(), 'A loyal customer', $owner);

        $this->travelTo('2026-02-10 12:00:00');
        chargeFees($tenant);

        expect(feeAmounts($contract))->toBe(['10.00', '10.00']);
    });

    it('runs again harmlessly and catches up the days it missed', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['amount' => '5', 'frequency' => 'daily']);
        $contract = lateContract($tenant);

        $this->travelTo('2026-02-03 12:00:00');
        expect(chargeFees($tenant))->toBe(2)
            ->and(chargeFees($tenant))->toBe(0);

        $this->travelTo('2026-02-06 12:00:00');
        expect(chargeFees($tenant))->toBe(3)
            ->and(feesOf($contract)->map(fn (LateFee $f) => $f->period)->all())->toBe([1, 2, 3, 4, 5]);
    });

    it('never charges a paid instalment, one set aside, an open contract or a cancelled one', function () {
        [$owner, $tenant] = owner();
        feePolicy($tenant);
        $paid = lateContract($tenant);
        payFee($paid, '100.00');
        $cancelled = lateContract($tenant);
        app(CancelContract::class)->handle($cancelled, 'Returned the goods', $owner);
        switchOn(Feature::OpenContracts);
        $converted = lateContract($tenant);
        app(ConvertToOpen::class)->handle($converted, $owner);

        $this->travelTo('2026-02-20 12:00:00');
        chargeFees($tenant);

        expect(feesOf($paid))->toBeEmpty()
            ->and(feesOf($cancelled))->toBeEmpty()
            ->and(feesOf($converted))->toBeEmpty();
    });

    it('charges nothing while the switch is off, and keeps what was charged', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'daily']);
        $contract = lateContract($tenant);
        $this->travelTo('2026-02-02 12:00:00');
        chargeFees($tenant);

        app(FeatureControl::class)->setState(Feature::LateFees, PlatformState::Off, null, null);
        $this->travelTo('2026-02-05 12:00:00');

        expect(chargeFees($tenant))->toBe(0)
            ->and(feesOf($contract))->toHaveCount(1);
    });

    it('copies the policy into the contract, so a later change leaves it alone', function () {
        [, $tenant] = owner();
        feePolicy($tenant);
        $contract = lateContract($tenant);
        TenantSettings::for($tenant)->set('late_fees.amount', '50');
        $later = lateContract($tenant);

        $this->travelTo('2026-02-02 12:00:00');
        chargeFees($tenant);

        expect(freshContract($contract)->late_fee_policy)->toMatchArray(['type' => 'fixed', 'amount' => '10.00', 'frequency' => 'once'])
            ->and(feeAmounts($contract))->toBe(['10.00'])
            ->and(feeAmounts($later))->toBe(['50.00']);
    });

    it('gives no fees to a contract made before there was a policy, or while the switch was off', function () {
        [, $tenant] = owner();
        $before = lateContract($tenant);
        switchOn(Feature::LateFees);
        $noAmount = lateContract($tenant);
        feePolicy($tenant);

        $this->travelTo('2026-02-02 12:00:00');
        chargeFees($tenant);

        expect(freshContract($before)->late_fee_policy)->toBeNull()
            ->and(freshContract($noAmount)->late_fee_policy)->toBeNull()
            ->and(feesOf($before))->toBeEmpty()
            ->and(feesOf($noAmount))->toBeEmpty();
    });

    it('gives no policy to a contract made while the switch is off, even with a fee set', function () {
        [, $tenant] = owner();
        feePolicy($tenant);
        app(FeatureControl::class)->setState(Feature::LateFees, PlatformState::Off, null, null);

        expect(freshContract(lateContract($tenant))->late_fee_policy)->toBeNull();
    });

    it('never charges for days before the contract was entered', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['frequency' => 'daily']);
        // An old deal typed in on 10 March: its February and March instalments were already late.
        $this->travelTo('2026-03-10 08:00:00');
        $contract = lateContract($tenant);

        $this->travelTo('2026-03-11 12:00:00');
        chargeFees($tenant);

        expect(feesOf($contract)->map(fn (LateFee $f) => [$f->charged_on->format('Y-m-d'), $f->installment->number])->sort()->values()->all())
            ->toBe([['2026-03-10', 1], ['2026-03-10', 2], ['2026-03-11', 1], ['2026-03-11', 2]]);
    });

    it('keeps a charity fee apart', function () {
        [, $tenant] = owner();
        feePolicy($tenant, ['charity' => true]);
        $contract = lateContract($tenant);

        $this->travelTo('2026-02-02 12:00:00');
        chargeFees($tenant);

        expect(feesOf($contract)->sole()->charity)->toBeTrue();
    });
});

describe('paying', function () {
    /** Late on 1 Feb and 1 Mar, each with a 10.00 fee; the April instalment not yet due. */
    function twoLate(): array
    {
        [$owner, $tenant] = owner();
        feePolicy($tenant);
        $contract = lateContract($tenant);
        test()->travelTo('2026-03-05 12:00:00');
        chargeFees($tenant);

        return [$owner, $tenant, $contract];
    }

    it('pays what is late first, then the fees, then what is not yet due', function () {
        [, , $contract] = twoLate();

        $payment = payFee($contract, '230.00');

        $toInstallments = asTenant($contract->tenant, fn () => TransactionAllocation::where('transaction_id', $payment->id)->with('installment')->get()
            ->mapWithKeys(fn ($a) => [$a->installment->number => Money::add($a->amount, '0', 2)])->all());
        $toFees = asTenant($contract->tenant, fn () => LateFeeAllocation::where('transaction_id', $payment->id)->sum('amount'));

        expect($toInstallments)->toBe([1 => '100.00', 2 => '100.00', 3 => '10.00'])
            ->and(Money::fromDatabase($toFees))->toBe('20.00')
            ->and(feesOf($contract)->pluck('status')->all())->toBe(['paid', 'paid']);
    });

    it('leaves the fees open when the payment only covers what is late', function () {
        [, , $contract] = twoLate();

        payFee($contract, '150.00');

        expect(feesOf($contract)->pluck('status')->all())->toBe(['open', 'open'])
            ->and(installmentsOfContract($contract)->pluck('status')->all())->toBe(['paid', 'partial', 'pending']);
    });

    it('counts open fees in what is owed', function () {
        [, , $contract] = twoLate();

        expect(validationErrors(fn () => payFee($contract, '320.01'))['amount'][0])->toContain('320.00');
        payFee($contract, '320.00');

        expect(freshContract($contract)->status)->toBe('settled');
    });

    it('puts the fees back exactly when the payment is voided', function () {
        [$owner, , $contract] = twoLate();
        $payment = payFee($contract, '230.00');

        app(VoidTransaction::class)->handle($payment, 'Wrong customer', $owner);

        expect(feesOf($contract)->map(fn (LateFee $f) => [$f->status, Money::add($f->paid_amount, '0', 2)])->all())->toBe([['open', '0.00'], ['open', '0.00']])
            ->and(installmentsOfContract($contract)->pluck('status')->all())->toBe(['pending', 'pending', 'pending']);
        expectConsistentFees($contract);
    });

    it('credits the investor only what went to instalments', function () {
        [, , $contract] = twoLate();
        $payment = payFee($contract, '230.00');

        $credited = asTenant($contract->tenant, fn () => InvestorEntry::where('transaction_id', $payment->id)->sum('amount'));

        expect(Money::fromDatabase($credited))->toBe('210.00');
    });

    it('credits the investor nothing for a payment that only paid fees', function () {
        [, , $contract] = twoLate();
        $installments = installmentsOfContract($contract);
        payFee($contract, '100.00', applyTo: $installments[0]->id);
        payFee($contract, '100.00', applyTo: $installments[1]->id);

        $feesOnly = payFee($contract, '20.00');

        expect(asTenant($contract->tenant, fn () => InvestorEntry::where('transaction_id', $feesOnly->id)->count()))->toBe(0);
    });

    it('settles the contract only once the fees are paid', function () {
        [$owner, , $contract] = twoLate();
        $installments = installmentsOfContract($contract);
        foreach ($installments as $installment) {
            payFee($contract, '100.00', applyTo: $installment->id);
        }
        expect(freshContract($contract)->status)->toBe('active');

        $fees = payFee($contract, '20.00');
        expect(freshContract($contract)->status)->toBe('settled');

        app(VoidTransaction::class)->handle($fees, 'Bounced', $owner);
        expect(freshContract($contract)->status)->toBe('active');
    });

    it('takes a payment across contracts with the fees in the same order', function () {
        [$owner, $tenant, $contract] = twoLate();
        switchOn(Feature::PaymentTargeting);

        $customer = asTenant($tenant, fn () => Customer::find($contract->customer_id));
        app(RecordCustomerPayment::class)->handle($customer, '220.00', 'cash', by: $owner);

        expect(feesOf($contract)->pluck('status')->all())->toBe(['paid', 'paid'])
            ->and(installmentsOfContract($contract)->pluck('status')->all())->toBe(['paid', 'paid', 'pending']);
    });

    it('shows on the statement: each fee, its waiver, and a closing balance that matches what is owed', function () {
        [$owner, , $contract] = twoLate();
        app(WaiveLateFee::class)->handle(feesOf($contract)->first(), 'First time late', $owner);

        $statement = asTenant($contract->tenant, fn () => Statement::of([freshContract($contract)]));

        expect(collect($statement->lines)->pluck('kind')->all())->toBe(['sale', 'late_fee', 'late_fee', 'late_fee_waived'])
            ->and($statement->closing)->toBe('310.00');
    });

    it('includes the fees in an early settlement, which takes nothing off them', function () {
        [$owner, , $contract] = twoLate();
        switchOn(Feature::EarlySettlement);

        $quote = asTenant($contract->tenant, fn () => EarlySettlementQuote::for(
            freshContract($contract), installmentsOfContract($contract), 'fixed', '50', LateFees::owed(freshContract($contract)),
        ));
        expect($quote->toArray())->toMatchArray(['remaining' => '300.00', 'late_fees' => '20.00', 'rebate' => '0.00', 'payoff' => '320.00']);

        app(RecordPayment::class)->settle(freshContract($contract), 'none', '0', '320.00', 'cash', by: $owner);

        expect(feesOf($contract)->pluck('status')->all())->toBe(['paid', 'paid'])
            ->and(freshContract($contract)->status)->toBe('settled');
    });

    it('takes an early-settlement discount off the instalments, never off a fee, even when everything is late', function () {
        [$owner, $tenant] = owner();
        feePolicy($tenant);
        switchOn(Feature::EarlySettlement);
        // 300.00 with a 10% markup: three instalments of 110.00, all late by 5 April, each with a 10.00 fee.
        $contract = openContract($tenant, ['markup_type' => 'percent', 'markup_value' => '10']);
        $this->travelTo('2026-04-05 12:00:00');
        chargeFees($tenant);

        // A 10.00 discount on the 30.00 of profit not yet earned: 330.00 - 10.00, and the 30.00 of fees in full.
        app(RecordPayment::class)->settle(freshContract($contract), 'fixed', '10', '350.00', 'cash', by: $owner);

        expect(feesOf($contract)->pluck('status')->all())->toBe(['paid', 'paid', 'paid'])
            ->and(freshContract($contract)->status)->toBe('settled');
    });

    it('refuses to make a contract open while it has fees to pay', function () {
        [$owner, , $contract] = twoLate();
        switchOn(Feature::OpenContracts);

        expect(validationErrors(fn () => app(ConvertToOpen::class)->handle(freshContract($contract), $owner))['contract'][0])
            ->toBe(__('Collect or waive the late fees before making this contract open.'));
    });
});

describe('waiving', function () {
    it('needs a reason, is audited, and settles the contract when it was all that was owed', function () {
        [$owner, , $contract] = twoLateOne();
        $fee = feesOf($contract)->sole();

        expect(validationErrors(fn () => app(WaiveLateFee::class)->handle($fee, '  ', $owner)))->toHaveKey('reason');

        app(WaiveLateFee::class)->handle($fee, 'Paid the day after, by mistake', $owner);

        $fee = feesOf($contract)->sole();
        expect($fee->status)->toBe('waived')
            ->and($fee->waived_reason)->toBe('Paid the day after, by mistake')
            ->and($fee->waived_by_user_id)->toBe($owner->id)
            ->and(freshContract($contract)->status)->toBe('settled')
            ->and(AuditLog::where('action', 'late_fee.waived')->where('subject_id', $fee->id)->exists())->toBeTrue();
    });

    it('waives only an open fee', function () {
        [$owner, , $contract] = twoLateOne();
        $fee = feesOf($contract)->sole();
        app(WaiveLateFee::class)->handle($fee, 'Goodwill', $owner);

        expect(validationErrors(fn () => app(WaiveLateFee::class)->handle(feesOf($contract)->sole(), 'Again', $owner)))->toHaveKey('fee');
    });

    it('keeps a waived fee waived when a payment that part-paid it is voided', function () {
        [$owner, , $contract] = twoLateOne();
        $part = payFee($contract, '4.00');
        app(WaiveLateFee::class)->handle(feesOf($contract)->sole(), 'Goodwill', $owner);

        app(VoidTransaction::class)->handle($part, 'Bounced', $owner);

        $fee = feesOf($contract)->sole();
        expect($fee->status)->toBe('waived')->and(Money::add($fee->paid_amount, '0', 2))->toBe('0.00');
        expectConsistentFees($contract);
    });
});

/** Every instalment paid on time except a fee of 10.00 on the first, which is all that is owed. */
function twoLateOne(): array
{
    [$owner, $tenant] = owner();
    feePolicy($tenant);
    $contract = lateContract($tenant);
    test()->travelTo('2026-02-02 12:00:00');
    chargeFees($tenant);
    foreach (installmentsOfContract($contract) as $installment) {
        payFee($contract, '100.00', applyTo: $installment->id);
    }

    return [$owner, $tenant, $contract];
}

/** Each fee's paid amount is the sum of its allocations. */
function expectConsistentFees(Contract $contract): void
{
    asTenant($contract->tenant, function () use ($contract): void {
        foreach (LateFee::where('contract_id', $contract->id)->get() as $fee) {
            $allocated = Money::fromDatabase(LateFeeAllocation::where('late_fee_id', $fee->id)->sum('amount'));
            expect(Money::cmp($fee->paid_amount, $allocated))->toBe(0, "fee {$fee->id} paid_amount differs from its allocations");
        }
    });
}

it('lets the workspace owner see who waived what', function () {
    [$owner, , $contract] = twoLateOne();
    app(WaiveLateFee::class)->handle(feesOf($contract)->sole(), 'Goodwill', $owner);

    expect(feesOf($contract)->sole()->waivedBy)->toBeInstanceOf(User::class);
});
