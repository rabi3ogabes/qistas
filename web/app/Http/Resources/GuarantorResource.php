<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\Guarantor;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guarantor
 *
 * A guarantor (Win Plan K5). The ID number is masked, and null for anyone who does not see ID numbers (collectors and
 * viewers): they still get the name and the phone, which is what a collector needs.
 */
class GuarantorResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $seesIds = $request->user()?->roleIn(app(CurrentTenant::class)->id() ?? '')?->seesIdentities() ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'relation' => $this->relation,
            'national_id' => $seesIds ? $this->maskedNationalId() : null,
            'created_at' => $this->moment($this->created_at),
        ];
    }
}
