<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message a store sent about a subscription (Win Plan PP13), kept once by its event id so a repeat changes nothing.
 * Platform data, not a workspace's: written only by App\Billing\StoreSubscriptions.
 *
 * @property string $id
 * @property string $provider
 * @property string $event_id
 * @property array<string, mixed> $payload
 * @property string|null $outcome
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 */
class StoreEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
