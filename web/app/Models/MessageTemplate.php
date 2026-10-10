<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's own wording for one reminder in one language (Win Plan PP9). Without a row the default wording, in the
 * translations, is used; see App\Reminders\ReminderTemplates.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $language
 * @property string $body
 */
#[Fillable(['key', 'language', 'body'])]
class MessageTemplate extends Model
{
    use BelongsToTenant, HasUuids;
}
