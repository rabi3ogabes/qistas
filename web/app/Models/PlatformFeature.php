<?php

namespace App\Models;

use App\Entitlements\PlatformState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform admin's switch for one feature (see PlatformFeatures). Keyed by the feature's own key, not a UUID, so
 * there can only ever be one row per feature. Changed only through FeatureControl, which audits every change.
 */
#[Fillable(['feature_key', 'state', 'reason', 'changed_by_user_id', 'changed_at'])]
class PlatformFeature extends Model
{
    protected $primaryKey = 'feature_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['state' => PlatformState::class, 'changed_at' => 'datetime'];
    }
}
