<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 *
 * One line of the money ledger. Amounts are signed: a payment is positive, its reversal negative. `voided` is true
 * for a payment that has been reversed (the line itself is never changed).
 */
class TransactionResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'method' => $this->method,
            'amount' => $this->money($this->amount),
            'paid_at' => $this->moment($this->paid_at),
            'note' => $this->note,
            'tag' => $this->tag,
            'voided' => (bool) $this->getAttribute('voided'),
            // On an open contract's ledger: what was owed once this line was written.
            'balance_after' => $this->when($this->getAttribute('balance_after') !== null, fn () => $this->money($this->getAttribute('balance_after'))),
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            // Who did what (Win Plan PP16): who wrote the line and when, and for a voided one who voided it and why.
            'recorded_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            'recorded_at' => $this->moment($this->created_at),
            'reversal' => $this->when($this->getAttribute('reversal') !== null, fn () => [
                'id' => $this->getAttribute('reversal')->id,
                'by' => $this->getAttribute('reversal')->createdBy === null ? null : ['id' => $this->getAttribute('reversal')->createdBy->id, 'name' => $this->getAttribute('reversal')->createdBy->name],
                'reason' => $this->getAttribute('reversal')->note,
                'at' => $this->moment($this->getAttribute('reversal')->created_at),
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : ['id' => $this->customer->id, 'name' => $this->customer->name]),
            'contract' => $this->whenLoaded('contract', fn () => [
                'id' => $this->contract->id,
                'reference' => $this->contract->reference(),
                'status' => $this->contract->status,
                'owed' => $this->when($this->contract->getAttribute('owed') !== null, fn () => $this->money($this->contract->getAttribute('owed'))),
            ]),
        ];
    }
}
