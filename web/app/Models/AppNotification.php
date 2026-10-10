<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry in a person's inbox in the app (Win Plan PP9): the same words as the push, kept for when the push was missed
 * or the phone had none.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'read_at', 'created_at'])]
class AppNotification extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}
