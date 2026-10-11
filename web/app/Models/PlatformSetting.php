<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of the platform's own settings, by key (billing switches, bank details, store keys). Read and written only
 * through App\Billing\BillingSettings, which encrypts the secret ones.
 *
 * @property string $key
 * @property string|null $value
 * @property string|null $updated_by_user_id
 * @property Carbon|null $updated_at
 */
class PlatformSetting extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
