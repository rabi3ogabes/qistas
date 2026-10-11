<?php

namespace App\Http\Resources;

use App\Domain\Investors\MainInvestor;
use App\Http\Resources\Concerns\Presents;
use App\Models\Contract;
use App\Models\Installment;
use App\Models\Investor;
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

    /** The due date the contract is paid up to, when that is still ahead of today; null otherwise. */
    private function paidAheadUntil(): ?string
    {
        $last = null;
        foreach ($this->installments->reject(fn (Installment $i) => in_array($i->status, Installment::SET_ASIDE, true))->sortBy(fn (Installment $i) => [$i->due_date->format('Y-m-d'), $i->number]) as $installment) {
            if ($installment->status !== 'paid') {
                break;
            }
            $last = $installment;
        }

        return $last !== null && $last->due_date->isAfter(today()) && $this->status === 'active' ? $this->day($last->due_date) : null;
    }

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
            'credit_limit' => $this->credit_limit === null ? null : $this->money($this->credit_limit),
            // Contract details (Win Plan PP7) and the discount at sale (PP6).
            'title' => $this->title,
            'own_reference' => $this->own_reference,
            'cost_price' => $this->cost_price === null ? null : $this->money($this->cost_price),
            'tax_percent' => $this->tax_percent === null ? null : $this->money($this->tax_percent),
            'tax_amount' => $this->tax_amount === null ? null : $this->money($this->tax_amount),
            'discount_type' => $this->discount_type,
            'discount_value' => $this->money($this->discount_value),
            'discount_amount' => $this->money($this->discount_amount),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'serial' => $item->serial,
                'cost' => $item->cost === null ? null : $this->money($item->cost),
                'price' => $item->price === null ? null : $this->money($item->price),
                'product_id' => $item->product_id,
            ])->values()->all()),
            'start_date' => $this->day($this->start_date),
            'first_due_date' => $this->day($this->first_due_date),
            'notes' => $this->notes,
            'created_at' => $this->moment($this->created_at),
            'settled_at' => $this->moment($this->settled_at),
            'cancelled_at' => $this->moment($this->cancelled_at),
            'archived' => $this->archived_at !== null,
            'archived_at' => $this->moment($this->archived_at),
            'customer' => $this->whenLoaded('customer', fn () => ['id' => $this->customer->id, 'name' => $this->customer->name]),
            // Who funded it, for the people who see investors (collectors do not).
            'investor' => $this->when(
                $this->relationLoaded('investor') && $this->investor !== null && ($request->user()?->can('viewAny', Investor::class) ?? false),
                fn () => ['id' => $this->investor->id, 'name' => MainInvestor::displayName($this->investor), 'is_main' => $this->investor->is_main],
            ),
            'owed' => $this->when($progress !== null, fn () => $this->money($this->status === 'cancelled' ? '0' : $progress['owed'])),
            'paid' => $this->when($this->getAttribute('paid') !== null, fn () => $this->money($this->getAttribute('paid'))),
            'next_installment' => $this->when($progress !== null, fn () => $next === null ? null : [
                'number' => $next->number,
                'due_date' => $this->day($next->due_date),
                'remaining' => $this->money($next->remaining()),
            ]),
            // Paid ahead (Win Plan PP6): the instalments from the first are all paid up to one not yet due.
            'paid_ahead_until' => $this->whenLoaded('installments', fn () => $this->paidAheadUntil()),
            // An early settlement's discount, net of its void if it was voided (Win Plan PP6).
            'early_settlement_rebate' => $this->whenLoaded('adjustments', fn () => $this->adjustments->isEmpty() ? null
                : $this->money($this->adjustments->reduce(fn (string $sum, $adjustment) => bcadd($sum, (string) $adjustment->amount, 4), '0'))),
            'amendments' => $this->whenLoaded('amendments', fn () => $this->amendments->map(fn ($amendment) => [
                'id' => $amendment->id,
                'type' => $amendment->type,
                'reason' => $amendment->reason,
                'old_schedule' => $amendment->old_schedule,
                'new_schedule' => $amendment->new_schedule,
                'approved_by' => $amendment->approvedBy === null ? null : ['id' => $amendment->approvedBy->id, 'name' => $amendment->approvedBy->name],
                'created_at' => $this->moment($amendment->created_at),
            ])->values()->all()),
            'installments' => $this->whenLoaded('installments', fn () => InstallmentResource::collection($this->installments)),
            'transactions' => $this->whenLoaded('transactions', fn () => TransactionResource::collection($this->transactions)),
        ];
    }
}
