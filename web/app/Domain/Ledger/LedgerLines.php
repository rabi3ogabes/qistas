<?php

namespace App\Domain\Ledger;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Who did what on a page of ledger lines (Win Plan PP16): each line already knows who recorded it; a voided one is
 * given its reversal (who voided it, when and why), fetched in one query for the whole page.
 */
final class LedgerLines
{
    /**
     * @template TCollection of Collection<int, Transaction>
     *
     * @param  TCollection  $lines
     * @return TCollection
     */
    public static function withReversals(Collection $lines): Collection
    {
        $reversals = Transaction::query()->with('createdBy')->whereIn('reverses_transaction_id', $lines->pluck('id'))->get()->keyBy('reverses_transaction_id');

        return $lines->each(function (Transaction $line) use ($reversals): void {
            $reversal = $reversals->get($line->id);
            $line->setAttribute('voided', $reversal !== null);
            $line->setAttribute('reversal', $reversal);
        });
    }
}
