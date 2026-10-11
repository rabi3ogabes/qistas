<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\LateFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LateFee
 *
 * One late fee (Win Plan K4): the instalment and period it is for, the day that period began, what it is and what is
 * still owed, and who waived it and why when it was waived.
 */
class LateFeeResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'installment_id' => $this->installment_id,
            'installment_number' => $this->whenLoaded('installment', fn () => $this->installment->number),
            'period' => $this->period,
            'charged_on' => $this->day($this->charged_on),
            'amount' => $this->money($this->amount),
            'paid_amount' => $this->money($this->paid_amount),
            'remaining' => $this->money($this->remaining()),
            'status' => $this->status,
            'charity' => $this->charity,
            'waived_reason' => $this->waived_reason,
            'waived_at' => $this->moment($this->waived_at),
            'waived_by' => $this->whenLoaded('waivedBy', fn () => $this->waivedBy === null ? null : ['id' => $this->waivedBy->id, 'name' => $this->waivedBy->name]),
        ];
    }
}
