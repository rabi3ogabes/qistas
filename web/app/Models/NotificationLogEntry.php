<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An alert decided once (Win Plan PP9): sent, or skipped because there was nothing to say. Its dedupe key is unique, so
 * the same alert can never be decided twice however often the scheduler runs.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $type
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $channel
 * @property string $status sent | skipped
 * @property string $dedupe_key
 * @property Carbon $sent_at
 */
#[Fillable(['user_id', 'type', 'subject_type', 'subject_id', 'channel', 'status', 'dedupe_key', 'sent_at'])]
class NotificationLogEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'notification_log';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
