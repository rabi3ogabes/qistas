<?php

namespace App\Actions;

use App\Domain\LateFees\LateFeeCalculator;
use App\Domain\LateFees\LateFeePolicy;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\LateFee;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Charges a workspace's late fees up to today, on its own calendar (Win Plan K4, decision D2): each running contract
 * with a policy gets the fees its late instalments owe and do not have yet. Safe to run as often as wanted: each
 * instalment's fee for a period is written once (unique instalment and period), and a run after days missed catches
 * them up. Nothing is charged while the feature is off for the workspace; fees already charged stay.
 */
final class ChargeLateFees
{
    public function __construct(private readonly CurrentTenant $current, private readonly LateFeeCalculator $calculator) {}

    /** @return int how many fees it charged */
    public function handle(Tenant $tenant, ?CarbonInterface $now = null): int
    {
        if (! Entitlements::for($tenant)->check(Feature::LateFees)->enabled()) {
            return 0;
        }
        $today = $tenant->localTime(($now ?? now())->copy())->format('Y-m-d');

        return $this->current->use($tenant, function () use ($tenant, $today): int {
            $late = Contract::query()->where('status', 'active')->where('type', 'scheduled')->whereNotNull('late_fee_policy')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('installments')
                    ->whereColumn('installments.contract_id', 'contracts.id')
                    ->whereNotIn('installments.status', Installment::CLOSED)
                    ->whereDate('installments.grace_until', '<', $today))
                ->pluck('id');

            $charged = 0;
            foreach ($late as $id) {
                $charged += DB::transaction(fn () => $this->charge($tenant, $id, $today));
            }

            return $charged;
        });
    }

    private function charge(Tenant $tenant, string $contractId, string $today): int
    {
        // Locked, so a payment or another run waits and then sees these fees.
        $contract = Contract::query()->whereKey($contractId)->lockForUpdate()->first();
        $policy = LateFeePolicy::fromArray($contract?->late_fee_policy);
        if ($contract === null || $policy === null || $contract->status !== 'active' || $contract->type !== 'scheduled') {
            return 0;
        }

        $installments = Installment::query()->where('contract_id', $contract->id)->whereNotIn('status', Installment::CLOSED)->get()
            ->map(fn (Installment $i) => [
                'id' => $i->id, 'number' => $i->number, 'grace_until' => ($i->grace_until ?? $i->due_date)->format('Y-m-d'), 'remaining' => $i->remaining(),
            ])->values()->all();
        $charged = LateFee::query()->where('contract_id', $contract->id)->get(['installment_id', 'period', 'amount'])
            ->map(fn (LateFee $fee) => ['installment_id' => $fee->installment_id, 'period' => $fee->period, 'amount' => (string) $fee->amount])->values()->all();
        $from = $tenant->localTime($contract->created_at->copy())->format('Y-m-d');

        $due = $this->calculator->due($policy, $installments, $charged, $from, $today);
        foreach ($due as $fee) {
            (new LateFee)->forceFill([
                'contract_id' => $contract->id,
                'installment_id' => $fee['installment_id'],
                'period' => $fee['period'],
                'charged_on' => $fee['charged_on'],
                'amount' => $fee['amount'],
                'charity' => $policy->charity,
            ])->save();
        }

        return count($due);
    }
}
