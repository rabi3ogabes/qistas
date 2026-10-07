<?php

namespace Tests\Support;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A throw-away tenant-owned model used only to test the tenancy layer. */
class Widget extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'widgets';

    protected $guarded = [];
}
