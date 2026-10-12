<?php

namespace App\Actions\Paperwork;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\Contract;
use App\Models\Guarantor;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a guarantor to a contract, or changes one (Win Plan K5, CT21). At most three on a contract, counted under a lock
 * so two people adding at once cannot make four. A blank ID number keeps the one stored; `remove_national_id` clears
 * it. The audit row names what changed, never the ID number.
 */
final class SaveGuarantor
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  array{name: string, phone: string, relation?: ?string, national_id?: ?string}  $data  validated by GuarantorRequest
     *
     * @throws ValidationException (field `guarantors`) when the contract already has three
     */
    public function add(Contract $contract, array $data, User $by): Guarantor
    {
        Entitlements::for($contract->tenant)->assertEnabled(Feature::Guarantors);

        return $this->current->use($contract->tenant, fn () => DB::transaction(function () use ($contract, $data, $by): Guarantor {
            Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $existing = Guarantor::query()->where('contract_id', $contract->id);

            if ((clone $existing)->count() >= Guarantor::MAX_PER_CONTRACT) {
                throw ValidationException::withMessages(['guarantors' => __('Most guarantors on a contract: :count.', ['count' => Guarantor::MAX_PER_CONTRACT])]);
            }

            $guarantor = (new Guarantor)->fill([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'relation' => $data['relation'] ?? null,
                'national_id' => $data['national_id'] ?? null,
            ])->forceFill([
                'contract_id' => $contract->id,
                'position' => (int) (clone $existing)->max('position') + 1,
                'created_by_user_id' => $by->id,
            ]);
            $guarantor->save();

            Audit::record('guarantor.added', $guarantor, ['contract_id' => $contract->id], $contract->tenant_id, $by->id);

            return $guarantor;
        }));
    }

    /**
     * @param  array{name: string, phone: string, relation?: ?string, national_id?: ?string, remove_national_id?: bool}  $data
     */
    public function update(Guarantor $guarantor, array $data, User $by): Guarantor
    {
        $contract = $guarantor->contract;
        Entitlements::for($contract->tenant)->assertEnabled(Feature::Guarantors);

        return $this->current->use($contract->tenant, function () use ($guarantor, $data, $by): Guarantor {
            $guarantor->fill([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'relation' => $data['relation'] ?? null,
            ]);

            if (($data['national_id'] ?? null) !== null) {
                $guarantor->national_id = $data['national_id'];
            } elseif ((bool) ($data['remove_national_id'] ?? false)) {
                $guarantor->national_id = null;
            }

            $changed = array_keys($guarantor->getDirty());
            $guarantor->save();

            if ($changed !== []) {
                Audit::record('guarantor.changed', $guarantor, ['contract_id' => $guarantor->contract_id, 'fields' => $changed], $guarantor->tenant_id, $by->id);
            }

            return $guarantor;
        });
    }
}
