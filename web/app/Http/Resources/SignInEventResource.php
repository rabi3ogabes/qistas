<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\Presents;
use App\Models\SignInEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SignInEvent
 *
 * One line of the signed-in person's own sign-in history (`GET /account/sign-ins`). The address and the country are the
 * person's own connection, shown to them; the user agent is stored for support and is not part of this.
 */
class SignInEventResource extends JsonResource
{
    use Presents;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'channel' => $this->channel,
            'outcome' => $this->outcome,
            'device_type' => $this->device_type,
            'os' => $this->os,
            'browser' => $this->browser,
            'app_version' => $this->app_version,
            'device_name' => $this->device_name,
            'ip' => $this->ip,
            'ip_country' => $this->ip_country,
            'proxy' => $this->proxy,
            'proxy_signals' => $this->proxy_signals,
            'created_at' => $this->moment($this->created_at),
        ];
    }
}
