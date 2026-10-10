<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\Contract;
use App\Models\Installment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contract
 *
 * A contract. `status` is what is stored (active, settled, cancelled); `state` adds "late" for a running contract
 * with an overdue instalment. `progress` (owed, next instalment, late) is worked out by the controller for the
 * whole page at once and attached to each model.
 */
class ContractResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{owed: string, next: ?Installment, late: bool}|null $progress */
        $progress = $this->getAttribute('progress');
        $running = $this->status === 'active';
        $next = $running ? ($progress['next'] ?? null) : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'number' => $this->number,
            'type' => $this->type,
            'status' => $this->status,
            'state' => $running && ($progress['late'] ?? false) ? 'late' : $this->status,
            'principal' => $this->money($this->principal),
            'down_payment' => $this->money($this->down_payment),
            'financed' => $this->money($this->financed),
            'markup_type' => $this->markup_type,
            'markup_value' => $this->money($this->markup_value),
            'markup_amount' => $this->money($this->markup_amount),
            'total' => $this->money($this->total),
            'installment_count' => $this->installment_count,
            'frequency' => $this->frequency,
            'grace_days' => $this->grace_days,
            'start_date' => $this->day($this->start_date),
            'first_due_date' => $this->day($this->first_due_date),
            'notes' => $this->notes,
            'created_at' => $this->moment($this->created_at),
            'settled_at' => $this->moment($this->settled_at),
            'cancelled_at' => $this->moment($this->cancelled_at),
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name]),
            'owed' => $this->when($progress !== null, fn () => $this->money($this->status === 'cancelled' ? '0' : $progress['owed'])),
            'paid' => $this->when($this->getAttribute('paid') !== null, fn () => $this->money($this->getAttribute('paid'))),
            'next_installment' => $this->when($progress !== null, fn () => $next === null ? null : [
                'number' => $next->number,
                'due_date' => $this->day($next->due_date),
                'remaining' => $this->money($next->remaining()),
            ]),
            'installments' => $this->whenLoaded('installments', fn () => InstallmentResource::collection($this->installments)),
            'transactions' => $this->whenLoaded('transactions', fn () => TransactionResource::collection($this->transactions)),
        ];
    }
}
