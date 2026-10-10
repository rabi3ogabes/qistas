<?php

namespace App\Actions;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\Contract;
use App\Models\ContractConversion;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a running scheduled or cash contract into an open one (Win Plan PP4). Its unpaid instalments are superseded,
 * kept in its history but no longer owed or late; what was still owed on them becomes one opening line ("carried over")
 * of the open contract, and from then on it is a running tab. A preview answers exactly what would happen and changes
 * nothing, so the owner sees it before confirming.
 */
final class ConvertToOpen
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @return array{superseded: int, opening_balance: string}
     *
     * @throws ValidationException|FeatureUnavailable|FeatureLocked
     */
    public function handle(Contract $contract, ?User $by = null, bool $preview = false): array
    {
        return $this->current->use($contract->tenant, function () use ($contract, $by, $preview): array {
            Entitlements::for($contract->tenant)->assertEnabled(Feature::OpenContracts);

            return DB::transaction(function () use ($contract, $by, $preview): array {
                $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
                if ($locked->isOpen() || $locked->status !== 'active') {
                    throw ValidationException::withMessages(['contract' => $locked->isOpen()
                        ? __('This contract is already open.')
                        : __('Only a running contract can become open.')]);
                }

                $unpaid = Installment::query()->where('contract_id', $locked->id)->whereNotIn('status', Installment::CLOSED)->lockForUpdate()->get();
                $outcome = [
                    'superseded' => $unpaid->count(),
                    'opening_balance' => Money::add($unpaid->reduce(fn (string $sum, Installment $i) => Money::add($sum, $i->remaining()), '0'), '0', 2),
                ];
                if ($preview) {
                    return $outcome;
                }

                Installment::query()->whereKey($unpaid->modelKeys())->update(['status' => 'superseded']);

                $opening = null;
                if (Money::isPositive($outcome['opening_balance'])) {
                    $opening = (new Transaction)->forceFill([
                        'contract_id' => $locked->id,
                        'customer_id' => $locked->customer_id,
                        'type' => 'charge',
                        'method' => 'other',
                        'amount' => $outcome['opening_balance'],
                        'paid_at' => now(),
                        'note' => __('Carried over from the schedule'),
                        'created_by_user_id' => $by?->id,
                    ]);
                    $opening->save();
                }

                (new ContractConversion)->forceFill([
                    'contract_id' => $locked->id,
                    'from_type' => $locked->type,
                    'superseded_count' => $outcome['superseded'],
                    'opening_amount' => $outcome['opening_balance'],
                    'transaction_id' => $opening?->id,
                    'created_by_user_id' => $by?->id,
                ])->save();

                $from = $locked->type;
                $locked->forceFill(['type' => 'open'])->save();

                Audit::record('contract.converted_to_open', $locked, [
                    'reference' => $locked->reference(), 'from' => $from, ...$outcome,
                ], tenantId: $locked->tenant_id, userId: $by?->id);

                return $outcome;
            });
        });
    }
}
