<?php

namespace App\Actions\Investors;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Entitlements\FeatureLocked;
use App\Entitlements\FeatureUnavailable;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/** Money an investor puts in (deposit) or takes out (withdrawal), written by hand. The one manual way into the ledger. */
final class RecordInvestorEntry
{
    public function __construct(private readonly CurrentTenant $current) {}

    /**
     * @param  'deposit'|'withdrawal'  $type
     * @param  string  $amount  positive, at most two decimals (validated by App\Http\Requests\InvestorEntryRequest)
     *
     * @throws FeatureLocked|FeatureUnavailable
     */
    public function handle(Investor $investor, string $type, string $amount, ?string $occurredOn = null, ?string $note = null, ?User $by = null): InvestorEntry
    {
        return $this->current->use($investor->tenant, function () use ($investor, $type, $amount, $occurredOn, $note, $by): InvestorEntry {
            Entitlements::for($investor->tenant)->assertEnabled(Feature::Investors);

            return DB::transaction(function () use ($investor, $type, $amount, $occurredOn, $note, $by): InvestorEntry {
                $amount = Money::add(Money::parse($amount), '0', 2);

                $entry = (new InvestorEntry)->forceFill([
                    'investor_id' => $investor->id,
                    'type' => $type,
                    'amount' => $type === 'withdrawal' ? Money::sub('0', $amount, 2) : $amount,
                    'note' => $note,
                    'occurred_on' => $occurredOn ?? today()->format('Y-m-d'),
                    'created_by_user_id' => $by?->id,
                ]);
                $entry->save();

                Audit::record("investor.{$type}", $entry, ['investor' => $investor->name, 'amount' => $entry->amount], tenantId: $investor->tenant_id, userId: $by?->id);

                return $entry;
            });
        });
    }
}
