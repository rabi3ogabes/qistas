<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call of the cron endpoint (see CronRunner). Written by the runner only; read by the admin overview and the
 * qistas:cron-status command. Outcome is running, ok or failed.
 *
 * @property string $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string $outcome
 * @property int $jobs
 * @property string|null $error
 */
#[Fillable(['started_at', 'finished_at', 'outcome', 'jobs', 'error'])]
class CronRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'jobs' => 'integer'];
    }
}
