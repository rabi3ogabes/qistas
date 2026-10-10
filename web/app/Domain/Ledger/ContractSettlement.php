<?php

namespace App\Domain\Ledger;

use App\Models\Contract;
use App\Models\Installment;
use Carbon\CarbonInterface;

/** Keeps a contract's status in line with its instalments after money moves in or out. */
final class ContractSettlement
{
    /**
     * Settled once every instalment is paid; active again if a reversal re-opens one. A cancelled contract
     * stays cancelled. The caller must already hold the contract's row lock.
     */
    public static function sync(Contract $contract, CarbonInterface $at): void
    {
        // An open contract is a running tab: a zero balance today is not the end of it.
        if ($contract->status === 'cancelled' || $contract->type === 'open') {
            return;
        }

        $allPaid = ! Installment::where('contract_id', $contract->id)->where('status', '!=', 'paid')->exists();

        if ($allPaid && $contract->status === 'active') {
            $contract->forceFill(['status' => 'settled', 'settled_at' => $at])->save();
        } elseif (! $allPaid && $contract->status === 'settled') {
            $contract->forceFill(['status' => 'active', 'settled_at' => null])->save();
        }
    }
}
