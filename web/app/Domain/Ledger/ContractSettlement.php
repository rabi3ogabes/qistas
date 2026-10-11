<?php

namespace App\Domain\Ledger;

use App\Models\Contract;
use App\Models\Installment;
use App\Models\LateFee;
use Carbon\CarbonInterface;

/** Keeps a contract's status in line with its instalments and late fees after money moves in or out. */
final class ContractSettlement
{
    /**
     * Settled once every instalment and late fee is paid (or set aside or waived); active again if a reversal re-opens
     * one. A cancelled contract stays cancelled. The caller must already hold the contract's row lock.
     */
    public static function sync(Contract $contract, CarbonInterface $at): void
    {
        // An open contract is a running tab: a zero balance today is not the end of it.
        if ($contract->status === 'cancelled' || $contract->type === 'open') {
            return;
        }

        // Settled when nothing is left owing: every instalment paid, or set aside by a plan change or an early
        // settlement, and every late fee paid or waived (Win Plan K4).
        $allPaid = ! Installment::where('contract_id', $contract->id)->whereNotIn('status', Installment::CLOSED)->exists()
            && ! LateFee::where('contract_id', $contract->id)->where('status', 'open')->exists();

        if ($allPaid && $contract->status === 'active') {
            $contract->forceFill(['status' => 'settled', 'settled_at' => $at])->save();
        } elseif (! $allPaid && $contract->status === 'settled') {
            $contract->forceFill(['status' => 'active', 'settled_at' => null])->save();
        }
    }
}
