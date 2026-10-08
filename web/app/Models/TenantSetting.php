<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One workspace's value for one setting. Written only through App\Settings\TenantSettings, which validates and audits. */
#[Fillable(['key', 'value'])]
class TenantSetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
