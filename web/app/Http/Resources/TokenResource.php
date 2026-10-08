<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin PersonalAccessToken
 *
 * An API access token as it is listed: who it is for and when it was used, never its secret. The secret is shown
 * once, when the token is created (see TokenController::store).
 */
class TokenResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->moment($this->created_at),
            'last_used_at' => $this->moment($this->last_used_at),
            'expires_at' => $this->moment($this->expires_at),
        ];
    }
}
