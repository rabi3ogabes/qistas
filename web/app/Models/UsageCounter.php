<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Monthly usage of a quota feature. Changed only by Entitlements::consume (an atomic update). */
class UsageCounter extends Model
{
    use HasUuids;
}
