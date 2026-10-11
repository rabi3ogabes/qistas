<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "What's new" in one app version, in one language (Win Plan PP17). Platform data, written by staff in
 * /admin/release-notes; the app shows the published ones after it updates.
 *
 * @property string $id
 * @property string $version
 * @property string $language
 * @property string $body
 * @property Carbon|null $published_at
 */
class ReleaseNote extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
