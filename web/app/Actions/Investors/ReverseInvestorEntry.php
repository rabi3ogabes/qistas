<?php

namespace App\Actions\Investors;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\InvestorEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a deposit or a withdrawal the only way the ledger allows: with the same amount, negated, pointing back at
 * it. What a contract or a payment wrote follows the contract: void the payment, and its entries are reversed with it.
 */
final class ReverseInvestorEntry
{
    public function __construct(private readonly CurrentTenant $current) {}

    /** @throws ValidationException|FeatureLocked|FeatureUnavailable */
    public function handle(InvestorEntry $entry, ?string $note = null, ?User $by = null): InvestorEntry
    {
        $tenant = $entry->investor->tenant;

        return $this->current->use($tenant, function () use ($tenant, $entry, $note, $by): InvestorEntry {
            Entitlements::for($tenant)->assertEnabled(Feature::Investors);

            return DB::transaction(function () use ($entry, $note, $by): InvestorEntry {
                $original = InvestorEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

                if (! in_array($original->type, InvestorEntry::MANUAL, true) || $original->reverses_entry_id !== null) {
                    throw ValidationException::withMessages(['entry' => __('Only a deposit or a withdrawal can be reversed here. To undo a payment, void it on its contract.')]);
                }
                if (InvestorEntry::query()->where('reverses_entry_id', $original->id)->exists()) {
                    throw ValidationException::withMessages(['entry' => __('This entry has already been reversed.')]);
                }

                $reversal = (new InvestorEntry)->forceFill([
                    'investor_id' => $original->investor_id,
                    'type' => $original->type,
                    'amount' => Money::sub('0', $original->amount, 2),
                    'reverses_entry_id' => $original->id,
                    'note' => $note,
                    'occurred_on' => today()->format('Y-m-d'),
                    'created_by_user_id' => $by?->id,
                ]);
                $reversal->save();

                Audit::record('investor.entry_reversed', $reversal, ['amount' => $original->amount], tenantId: $original->tenant_id, userId: $by?->id);

                return $reversal;
            });
        });
    }
}
