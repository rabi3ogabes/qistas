<?php

namespace App\Actions;

use App\Domain\Ledger\ContractSettlement;
use App\Models\Contract;
use App\Models\LateFee;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Forgives what a late fee still owes (Win Plan K4, decision D3), with the reason kept and audited. What was already
 * paid on it stays paid. Works whether the feature is on or off: fees already charged can always be waived. When the
 * fee was the last thing owed, the contract is settled.
 */
final class WaiveLateFee
{
    public const REASON_MAX = 500;

    public function __construct(private readonly CurrentTenant $current) {}

    /** @throws ValidationException naming the field at fault */
    public function handle(LateFee $fee, ?string $reason, ?User $by = null): LateFee
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Say why the fee is waived.')]);
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => __('Keep the reason to :max characters.', ['max' => self::REASON_MAX])]);
        }

        return $this->current->use($fee->tenant, fn () => DB::transaction(function () use ($fee, $reason, $by): LateFee {
            $contract = Contract::query()->whereKey($fee->contract_id)->lockForUpdate()->firstOrFail();
            $locked = LateFee::query()->whereKey($fee->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'open' || $contract->status === 'cancelled') {
                throw ValidationException::withMessages(['fee' => __('Only a fee that is still owed can be waived.')]);
            }

            $forgiven = Money::add($locked->remaining(), '0', 2);
            $locked->forceFill(['status' => 'waived', 'waived_reason' => $reason, 'waived_by_user_id' => $by?->id, 'waived_at' => now()])->save();
            Audit::record('late_fee.waived', $locked, ['contract_id' => $contract->id, 'amount' => $forgiven, 'reason' => $reason], $locked->tenant_id, $by?->id);

            ContractSettlement::sync($contract, now());

            return $locked;
        }));
    }
}
