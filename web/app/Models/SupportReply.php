<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Qistas team's answer to a support ticket (Win Plan PP17). Written only by App\Help\SupportTickets.
 *
 * @property string $id
 * @property string $support_ticket_id
 * @property string|null $user_id
 * @property string $body
 * @property Carbon $created_at
 */
class SupportReply extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
