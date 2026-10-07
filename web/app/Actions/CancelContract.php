<?php

namespace App\Actions;

use App\Models\Contract;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends a running contract. Nothing is deleted: the schedule and every ledger line stay for the record, the
 * contract simply stops counting as running (its place on the plan is free again) and stops taking payments.
 */
final class CancelContract
{
    public function __construct(private readonly CurrentTenant $current) {}

    /** @throws ValidationException when the contract is not running */
    public function handle(Contract $contract, ?string $reason = null, ?User $by = null): Contract
    {
        return $this->current->use($contract->tenant, fn () => DB::transaction(function () use ($contract, $reason, $by): Contract {
            // Locked so a payment arriving at the same moment either lands first or finds the contract cancelled.
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['contract' => $locked->status === 'cancelled'
                    ? __('This contract is already cancelled.')
                    : __('A settled contract cannot be cancelled.')]);
            }

            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            Audit::record('contract.cancelled', $locked, array_filter([
                'reference' => $locked->reference(),
                'reason' => $reason,
            ]), tenantId: $locked->tenant_id, userId: $by?->id);

            return $locked;
        }));
    }
}
