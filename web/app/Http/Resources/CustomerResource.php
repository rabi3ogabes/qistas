<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 *
 * A customer. The national ID is only ever shown masked (its last three digits); the full number never leaves
 * the server. `owed` and `running_contracts` are present when the controller worked them out for the list.
 */
class CustomerResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'phone_secondary' => $this->phone_secondary,
            'email' => $this->email,
            'national_id' => $this->maskedNationalId(),
            'address' => $this->address,
            'notes' => $this->notes,
            'job' => $this->job,
            'created_at' => $this->moment($this->created_at),
            'owed' => $this->when($this->getAttribute('owed') !== null, fn () => $this->money($this->getAttribute('owed'))),
            'running_contracts' => $this->when($this->getAttribute('running_contracts') !== null, fn () => (int) $this->getAttribute('running_contracts')),
            'contracts' => $this->whenLoaded('contracts', fn () => ContractResource::collection($this->contracts)),
        ];
    }
}
