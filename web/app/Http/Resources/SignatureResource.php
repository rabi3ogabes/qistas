<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\ContractSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContractSignature
 *
 * The customer's signature on a contract (Win Plan K5): who signed (their name then), when, how (on the owner's screen
 * or through a link on their own phone) and the picture as a PNG data URI. Where it was signed from is kept, not shown.
 */
class SignatureResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'method' => $this->method,
            'signed_at' => $this->moment($this->signed_at),
            'image' => $this->dataUri(),
        ];
    }
}
