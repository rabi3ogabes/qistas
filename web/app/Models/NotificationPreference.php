<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One person's choice for one kind of alert in one workspace (Win Plan PP9). Read through App\Notifications\Preferences,
 * which supplies the defaults for kinds with no row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $type
 * @property string $channel
 * @property bool $enabled
 * @property array<string, string>|null $settings
 */
#[Fillable(['user_id', 'type', 'channel', 'enabled', 'settings'])]
class NotificationPreference extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'settings' => 'array'];
    }
}
