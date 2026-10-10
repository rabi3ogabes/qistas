<?php

namespace App\Actions;

use App\Http\ApiException;
use App\Models\Contract;
use App\Models\User;
use App\Support\Audit;

/**
 * Puts a finished contract away, or brings it back (Win Plan PP12). Only a settled or cancelled contract can be archived:
 * it then leaves the lists until asked for, and changes nothing else (investors still count it, its ledger is untouched).
 */
final class ArchiveContract
{
    /** @throws ApiException contract_running (422) when the contract is still running */
    public function handle(Contract $contract, bool $archive, User $by): Contract
    {
        if ($archive && $contract->status === 'active') {
            throw new ApiException('contract_running', __('Only a settled or cancelled contract can be archived.'), 422);
        }

        if (($contract->archived_at !== null) !== $archive) {
            $contract->forceFill(['archived_at' => $archive ? now() : null])->save();
            Audit::record($archive ? 'contract.archived' : 'contract.unarchived', $contract, ['reference' => $contract->reference()], $contract->tenant_id, $by->id);
        }

        return $contract;
    }
}
