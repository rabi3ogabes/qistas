<?php

use App\Actions\AmendContract;
use App\Actions\RecordPayment;
use App\Entitlements\Feature;
use App\Entitlements\FeatureControl;
use App\Entitlements\PlatformState;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Installment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/*
| Win Plan PP6: changing the plan. A new amount or count for what is left, skipping a month, or moving one date. The
| instalments nobody has paid towards are superseded and new ones written in their place; paid history never moves.
| A reason is required and only an owner or a manager confirms. After any change, paid plus unpaid is the agreed total.
|
| The contract: 1,100.00 in four monthly instalments of 275.00 from 1 November (as in EarlySettlementTest).
*/

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00:00');
    switchOn(Feature::Reschedule);
});

/** @return array{0: User, 1: Tenant, 2: Contract} */
function amendable(): array
{
    [$owner, $tenant] = apiOwner();
    $contract = openContract($tenant, [
        'principal' => '1200.00', 'down_payment' => '200.00', 'markup_type' => 'percent', 'markup_value' => '10',
        'installment_count' => 4, 'start_date' => '2026-10-01', 'first_due_date' => '2026-11-01',
    ]);
    app(RecordPayment::class)->handle($contract, '275.00', 'cash');

    return [$owner, $tenant, $contract];
}

/** @return list<array{number: int, due_date: string, amount: string, status: string}> */
function liveRows(Contract $contract): array
{
    return installmentsOfContract($contract)->where('status', '!=', 'superseded')->sortBy('due_date')->values()
        ->map(fn (Installment $i) => ['number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => Money::add($i->amount, '0', 2), 'status' => $i->status])->all();
}

/** Paid so far plus what is still owed on the live instalments: the agreed total, whatever has changed. */
function expectAgreedTotal(Contract $contract): void
{
    $rows = installmentsOfContract($contract);
    $paid = $rows->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->paid_amount), '0');
    $owed = $rows->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->remaining()), '0');

    expect(Money::add($paid, $owed, 2))->toBe(Money::add(Contract::withoutGlobalScopes()->find($contract->id)->total, '0', 2));
}

describe('a new count or amount for what is left', function () {
    it('previews the old and the new schedule side by side, and changes nothing', function () {
        [, , $contract] = amendable();

        $preview = $this->postJson("/api/v1/contracts/{$contract->id}/amendments/preview", ['type' => 'reschedule', 'count' => 7, 'first_due_date' => '2026-12-01'])
            ->assertOk()->json('data');

        expect(array_column($preview['old_schedule'], 'amount'))->toBe(['275.00', '275.00', '275.00'])
            // 825.00 over seven: 117.85 six times and 117.90 last.
            ->and(array_column($preview['new_schedule'], 'amount'))->toBe(['117.85', '117.85', '117.85', '117.85', '117.85', '117.85', '117.90'])
            ->and($preview['new_schedule'][6]['due_date'])->toBe('2027-06-01')
            ->and(liveRows($contract))->toHaveCount(4);
    });

    it('supersedes the unpaid instalments, writes the new ones and keeps the record, with the reason', function () {
        [$owner, $tenant, $contract] = amendable();
        $paidBefore = installmentsOfContract($contract)->where('status', 'paid')->map->only(['id', 'number', 'due_date', 'amount', 'paid_amount', 'status'])->values()->all();

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", ['type' => 'reschedule', 'count' => 7, 'first_due_date' => '2026-12-01', 'reason' => 'Lost his job; smaller instalments'])
            ->assertCreated()->assertJsonPath('data.amendment.type', 'reschedule');

        $rows = liveRows($contract);
        expect($rows)->toHaveCount(8)
            ->and($rows[0]['status'])->toBe('paid')
            ->and(array_column(array_slice($rows, 1), 'amount'))->toBe(['117.85', '117.85', '117.85', '117.85', '117.85', '117.85', '117.90'])
            ->and(installmentsOfContract($contract)->where('status', 'superseded'))->toHaveCount(3)
            ->and(installmentsOfContract($contract)->where('status', 'paid')->map->only(['id', 'number', 'due_date', 'amount', 'paid_amount', 'status'])->values()->all())->toEqual($paidBefore);
        asTenant($tenant, function () use ($owner) {
            $amendment = ContractAmendment::sole();
            expect($amendment->reason)->toBe('Lost his job; smaller instalments')->and($amendment->approved_by_user_id)->toBe($owner->id)
                ->and($amendment->old_schedule)->toHaveCount(3)->and($amendment->new_schedule)->toHaveCount(7);
        });
        expectAgreedTotal($contract);
    });

    it('takes an amount instead, with the remainder last', function () {
        [, , $contract] = amendable();

        $new = $this->postJson("/api/v1/contracts/{$contract->id}/amendments/preview", ['type' => 'reschedule', 'amount' => '300', 'first_due_date' => '2026-12-01'])->json('data.new_schedule');

        expect(array_column($new, 'amount'))->toBe(['300.00', '300.00', '225.00']);
    });

    it('leaves a part-paid instalment as it is and changes only the untouched ones', function () {
        [, , $contract] = amendable();
        app(RecordPayment::class)->handle(Contract::withoutGlobalScopes()->find($contract->id), '125.00', 'cash');

        $preview = $this->postJson("/api/v1/contracts/{$contract->id}/amendments/preview", ['type' => 'reschedule', 'count' => 1, 'first_due_date' => '2027-01-01'])->json('data');

        expect(array_column($preview['old_schedule'], 'amount'))->toBe(['275.00', '275.00'])
            ->and(array_column($preview['new_schedule'], 'amount'))->toBe(['550.00']);
    });
});

describe('skipping a month and moving a date', function () {
    it('skips a month: that instalment and the later ones move one step on', function () {
        [, , $contract] = amendable();
        $second = installmentsOfContract($contract)->firstWhere('number', 2);

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", ['type' => 'skip', 'installment_id' => $second->id, 'reason' => 'Eid'])->assertCreated();

        expect(array_column(liveRows($contract), 'due_date'))->toBe(['2026-11-01', '2027-01-01', '2027-02-01', '2027-03-01']);
        expectAgreedTotal($contract);
    });

    it('skipping a later month leaves the instalments before it where they were', function () {
        [, , $contract] = amendable();
        $third = installmentsOfContract($contract)->firstWhere('number', 3);

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", ['type' => 'skip', 'installment_id' => $third->id, 'reason' => 'Travel'])->assertCreated();

        expect(array_column(liveRows($contract), 'due_date'))->toBe(['2026-11-01', '2026-12-01', '2027-02-01', '2027-03-01']);
        expectAgreedTotal($contract);
    });

    it('moves one date, keeping the schedule in date order', function () {
        [, , $contract] = amendable();
        $third = installmentsOfContract($contract)->firstWhere('number', 3);

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", ['type' => 'move', 'installment_id' => $third->id, 'due_date' => '2027-03-15', 'reason' => 'Salary moved'])->assertCreated();

        $rows = liveRows($contract);
        expect(array_column($rows, 'due_date'))->toBe(['2026-11-01', '2026-12-01', '2027-02-01', '2027-03-15'])
            ->and(array_column($rows, 'number'))->toBe(array_values(collect($rows)->pluck('number')->sort()->all()));
        expectAgreedTotal($contract);
    });

    it('will not move an instalment someone has paid towards', function () {
        [, , $contract] = amendable();
        $first = installmentsOfContract($contract)->firstWhere('number', 1);

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", ['type' => 'move', 'installment_id' => $first->id, 'due_date' => '2027-03-15', 'reason' => 'x'])->assertStatus(422);
    });
});

describe('who and why', function () {
    it('asks for a reason', function () {
        [, , $contract] = amendable();

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", ['type' => 'reschedule', 'count' => 2, 'first_due_date' => '2026-12-01'])
            ->assertStatus(422)->assertJsonValidationErrors('reason', 'error.fields');
    });

    it('lets a collector preview but only an owner or a manager confirm', function () {
        [, $tenant, $contract] = amendable();
        apiMember('collector', $tenant);
        $change = ['type' => 'reschedule', 'count' => 2, 'first_due_date' => '2026-12-01', 'reason' => 'Asked'];

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments/preview", $change)->assertOk();
        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", $change)->assertForbidden();

        apiMember('manager', $tenant);
        $this->postJson("/api/v1/contracts/{$contract->id}/amendments", $change)->assertCreated();
    });

    it('is closed while the platform has it switched off', function () {
        [, , $contract] = amendable();
        app(FeatureControl::class)->setState(Feature::Reschedule, PlatformState::Off, null, null);

        $this->postJson("/api/v1/contracts/{$contract->id}/amendments/preview", ['type' => 'reschedule', 'count' => 2, 'first_due_date' => '2026-12-01'])
            ->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');
    });
});

it('keeps paid plus unpaid at the agreed total through any mix of payments and changes', function () {
    mt_srand(20261011);

    foreach (range(1, 12) as $round) {
        [$owner, , $contract] = amendable();
        foreach (range(1, 8) as $step) {
            $fresh = Contract::withoutGlobalScopes()->find($contract->id);
            $untouched = installmentsOfContract($contract)->where('status', 'pending')->where('paid_amount', '0.0000')->values();
            $owed = installmentsOfContract($contract)->reduce(fn (string $s, Installment $i) => Money::add($s, $i->remaining()), '0');
            $paidBefore = installmentsOfContract($contract)->where('status', 'paid')->map->only(['id', 'amount', 'paid_amount', 'due_date'])->values()->all();

            $move = mt_rand(1, 4);
            if ($move === 1 && Money::isPositive($owed)) {
                $amount = Money::add((string) (mt_rand(1, (int) bcmul(Money::add($owed, '0', 2), '100', 0)) / 100), '0', 2);
                app(RecordPayment::class)->handle($fresh, number_format((float) $amount, 2, '.', ''), 'cash');
            } elseif ($untouched->isNotEmpty()) {
                $change = match ($move) {
                    2 => ['type' => 'reschedule', 'count' => mt_rand(1, 9), 'first_due_date' => '2027-0'.mt_rand(1, 9).'-0'.mt_rand(1, 9)],
                    3 => ['type' => 'skip', 'installment_id' => $untouched->random()->id],
                    default => ['type' => 'move', 'installment_id' => $untouched->random()->id, 'due_date' => '2027-1'.mt_rand(0, 2).'-1'.mt_rand(0, 9)],
                };
                try {
                    app(AmendContract::class)->handle($fresh, $change + ['reason' => "round {$round} step {$step}"], $owner);
                } catch (ValidationException) {
                    // A change the rules refuse (too little left for that many instalments) changes nothing.
                }
            }

            expectAgreedTotal($contract);
            $paidAfter = installmentsOfContract($contract)->whereIn('id', array_column($paidBefore, 'id'))->map->only(['id', 'amount', 'paid_amount', 'due_date'])->values()->all();
            expect($paidAfter)->toEqual($paidBefore);
        }
        expectConsistentLedger($contract);
    }
});
