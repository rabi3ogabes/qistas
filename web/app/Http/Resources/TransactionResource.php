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
            'voided' => (bool) $this->getAttribute('voided'),
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
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
