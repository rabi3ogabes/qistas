<?php

namespace App\Http\Api;

use App\Domain\Investors\InvestorSummary;
use App\Domain\Investors\MainInvestor;
use App\Models\Contract;
use App\Models\Investor;
use App\Models\InvestorEntry;
use App\Support\Money;

/** How investors, their entries and their contracts read in the API (docs/api/openapi.yaml: Investor, InvestorEntry). */
final class InvestorPayload
{
    /** @return array<string, mixed> */
    public static function investor(Investor $investor): array
    {
        return [
            'id' => $investor->id,
            'name' => MainInvestor::displayName($investor),
            'is_main' => $investor->is_main,
            'commercial_registration' => $investor->commercial_registration,
            'currency' => $investor->currency,
            'commission_percent' => Money::add($investor->commission_percent, '0', 2),
            'notes' => $investor->notes,
            'archived' => $investor->isArchived(),
            'summary' => InvestorSummary::for($investor),
        ];
    }

    /**
     * @param  bool  $reversed  a later entry reverses this one
     * @return array<string, mixed>
     */
    public static function entry(InvestorEntry $entry, bool $reversed = false): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type,
            'amount' => Money::add($entry->amount, '0', 2),
            'occurred_on' => $entry->occurred_on->format('Y-m-d'),
            'note' => $entry->note,
            'contract' => $entry->contract === null ? null : ['id' => $entry->contract->id, 'reference' => $entry->contract->reference()],
            'reverses_entry_id' => $entry->reverses_entry_id,
            'reversed' => $reversed,
            'reversible' => in_array($entry->type, InvestorEntry::MANUAL, true) && $entry->reverses_entry_id === null && ! $reversed,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }

    /**
     * The contracts an investor funds, newest first, with what came back to them from each.
     *
     * @return list<array<string, mixed>>
     */
    public static function contracts(Investor $investor, int $limit = 50): array
    {
        $contracts = Contract::query()->where('investor_id', $investor->id)->with('customer')->orderByDesc('number')->limit($limit)->get();
        $back = InvestorEntry::query()->where('investor_id', $investor->id)->whereIn('contract_id', $contracts->pluck('id'))
            ->whereIn('type', ['principal_back', 'profit_share'])->selectRaw('contract_id, COALESCE(SUM(amount), 0) as total')
            ->groupBy('contract_id')->pluck('total', 'contract_id');

        return $contracts->map(fn (Contract $contract) => [
            'id' => $contract->id,
            'reference' => $contract->reference(),
            'customer' => $contract->customer === null ? null : ['id' => $contract->customer->id, 'name' => $contract->customer->name],
            'status' => $contract->status,
            'financed' => Money::add($contract->financed, '0', 2),
            'markup_amount' => Money::add($contract->markup_amount, '0', 2),
            'collected' => Money::fromDatabase($back[$contract->id] ?? 0),
            'start_date' => $contract->start_date->format('Y-m-d'),
        ])->values()->all();
    }
}
