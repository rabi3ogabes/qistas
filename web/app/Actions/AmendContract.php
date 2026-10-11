<?php

namespace App\Actions;

use App\Domain\Schedule\AmendmentPlanner;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Installment;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Changes a contract's plan (Win Plan PP6). Only the instalments nobody has paid towards change: they are superseded
 * (kept as history, marked with this change) and new ones are written after the last number, in date order. Paid and
 * part-paid instalments never move, and the new ones add up to exactly what the old ones did. The reason and the person
 * who confirmed it are kept with the old and new schedules.
 */
final class AmendContract
{
    public function __construct(private readonly CurrentTenant $current, private readonly AmendmentPlanner $planner) {}

    /**
     * What the change would do, writing nothing.
     *
     * @param  array<string, mixed>  $change
     * @return array{old_schedule: list<array{number: int, due_date: string, amount: string}>, new_schedule: list<array{due_date: string, amount: string}>}
     *
     * @throws ValidationException naming the field at fault
     */
    public function preview(Contract $contract, array $change): array
    {
        return $this->current->use($contract->tenant, function () use ($contract, $change): array {
            $this->assertChangeable($contract);
            $untouched = $this->untouched(Installment::query()->where('contract_id', $contract->id)->get());

            return ['old_schedule' => $this->schedule($untouched), 'new_schedule' => $this->plan($contract, $untouched, $change)];
        });
    }

    /**
     * @param  array<string, mixed>  $change  the change, with its reason
     *
     * @throws ValidationException naming the field at fault
     */
    public function handle(Contract $contract, array $change, User $by): ContractAmendment
    {
        $reason = trim((string) ($change['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Say why the plan is changing.')]);
        }

        return $this->current->use($contract->tenant, fn () => DB::transaction(function () use ($contract, $change, $reason, $by): ContractAmendment {
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($locked);
            $all = Installment::query()->where('contract_id', $locked->id)->lockForUpdate()->get();
            $untouched = $this->untouched($all);
            $new = $this->plan($locked, $untouched, $change);

            $number = (int) $all->max('number');
            $numbered = [];
            foreach ($new as $row) {
                $numbered[] = ['number' => ++$number] + $row;
            }

            $amendment = (new ContractAmendment)->forceFill([
                'contract_id' => $locked->id,
                'type' => (string) $change['type'],
                'reason' => $reason,
                'old_schedule' => $this->schedule($untouched),
                'new_schedule' => $numbered,
                'created_by_user_id' => $by->id,
                'approved_by_user_id' => $by->id,
            ]);
            $amendment->save();

            foreach ($untouched as $installment) {
                $installment->forceFill(['status' => 'superseded', 'amendment_id' => $amendment->id])->save();
            }
            foreach ($numbered as $row) {
                (new Installment)->forceFill([
                    'contract_id' => $locked->id,
                    'number' => $row['number'],
                    'due_date' => $row['due_date'],
                    'grace_until' => Carbon::parse($row['due_date'])->addDays((int) $locked->grace_days)->format('Y-m-d'),
                    'amount' => $row['amount'],
                    'amendment_id' => $amendment->id,
                ])->save();
            }

            Audit::record('contract.amended', $locked, ['type' => $amendment->type, 'reason' => $reason], $locked->tenant_id, $by->id);

            return $amendment;
        }));
    }

    private function assertChangeable(Contract $contract): void
    {
        if ($contract->status !== 'active' || $contract->isOpen() || $contract->type === 'cash') {
            throw ValidationException::withMessages(['contract' => __('Only a running contract with a schedule can change its plan.')]);
        }
    }

    /**
     * The instalments nobody has paid towards, in date order: the only ones a change may touch.
     *
     * @param  Collection<int, Installment>  $all
     * @return Collection<int, Installment>
     */
    private function untouched(Collection $all): Collection
    {
        return $all->filter(fn (Installment $i) => $i->status === 'pending' && bccomp((string) $i->paid_amount, '0', 4) === 0)
            ->sortBy(fn (Installment $i) => [$i->due_date->format('Y-m-d'), $i->number])->values();
    }

    /**
     * @param  Collection<int, Installment>  $untouched
     * @param  array<string, mixed>  $change
     * @return list<array{due_date: string, amount: string}>
     */
    private function plan(Contract $contract, Collection $untouched, array $change): array
    {
        try {
            return $this->planner->plan(
                $untouched->map(fn (Installment $i) => ['id' => $i->id, 'number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => (string) $i->amount])->all(),
                (string) $contract->frequency,
                $change + ['type' => ''],
                CarbonImmutable::today(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([AmendmentPlanner::FIELDS[$e->getCode()] ?? 'type' => $e->getMessage()]);
        }
    }

    /**
     * @param  Collection<int, Installment>  $installments
     * @return list<array{number: int, due_date: string, amount: string}>
     */
    private function schedule(Collection $installments): array
    {
        return $installments->map(fn (Installment $i) => ['number' => $i->number, 'due_date' => $i->due_date->format('Y-m-d'), 'amount' => bcadd((string) $i->amount, '0', 2)])->values()->all();
    }
}
