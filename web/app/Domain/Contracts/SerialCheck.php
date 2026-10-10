<?php

namespace App\Domain\Contracts;

use App\Models\Contract;
use App\Models\ContractItem;

/**
 * Serials and IMEIs sold on more than one running contract (Win Plan PP7). It warns and never refuses: a phone can come
 * back and be sold again, and the shop knows its own stock; but a typo or a mix-up is caught at the counter.
 */
final class SerialCheck
{
    /** @return list<array{field: string, message: string}> one warning for each item whose serial is on another running contract */
    public static function warnings(Contract $contract): array
    {
        $warnings = [];
        foreach (ContractItem::query()->where('contract_id', $contract->id)->whereNotNull('serial')->orderBy('position')->get() as $item) {
            $elsewhere = Contract::query()->where('status', 'active')->whereKeyNot($contract->id)
                ->whereHas('items', fn ($query) => $query->whereRaw('lower(serial) = ?', [mb_strtolower((string) $item->serial)]))
                ->first();
            if ($elsewhere !== null) {
                $warnings[] = [
                    'field' => 'items.'.($item->position - 1).'.serial',
                    'message' => __(':serial is also on contract :reference, which is still running.', ['serial' => $item->serial, 'reference' => $elsewhere->reference()]),
                ];
            }
        }

        return $warnings;
    }
}
