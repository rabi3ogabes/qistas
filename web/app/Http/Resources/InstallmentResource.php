<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\Installment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Installment
 *
 * One scheduled payment. `state` is what a person should see: paid, overdue, partial (part-paid, not yet due)
 * or upcoming; `status` is what the ledger stored.
 */
class InstallmentResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'due_date' => $this->day($this->due_date),
            'amount' => $this->money($this->amount),
            'paid_amount' => $this->money($this->paid_amount),
            'remaining' => $this->money($this->remaining()),
            'status' => $this->status,
            'state' => $this->displayState(),
            'paid_at' => $this->moment($this->paid_at),
        ];
    }
}
