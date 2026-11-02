<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One line of a person's sign-in history, written by App\Auth\SignInRecorder; never edited or deleted by the app.
 *
 * It is owned by the person, not by a workspace: one person can belong to several, and their history is theirs
 * wherever they sign in. So, unlike the models that hold a business's data, it has no BelongsToTenant and no tenant_id,
 * and it is only ever read through User::signInEvents() or by platform staff. It stores nothing a person typed.
 *
 * Append-only like AuditLog: an instance refuses update and delete. Only `qistas:sign-ins:prune` removes rows, with a
 * query-builder delete that skips model events on purpose (see App\Console\Commands\PruneSignInsCommand).
 *
 * @property string $id
 * @property string $user_id
 * @property string $method password (otp, google and microsoft arrive later)
 * @property string $channel web or app
 * @property string $outcome success or failed
 * @property string $device_type desktop, mobile, tablet or unknown
 * @property string|null $os
 * @property string|null $browser
 * @property string|null $app_version
 * @property string|null $device_name
 * @property string $ip
 * @property string|null $ip_country
 * @property string $proxy not_detected, possible or detected
 * @property list<string> $proxy_signals tor, via_header, forwarded_chain, timezone_country_mismatch
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'method', 'channel', 'outcome', 'device_type', 'os', 'browser', 'app_version', 'device_name', 'ip', 'ip_country', 'proxy', 'proxy_signals', 'user_agent'])]
class SignInEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sign-in records are append-only.'));
        static::deleting(fn () => throw new LogicException('Sign-in records are append-only.'));
    }

    protected function casts(): array
    {
        return ['proxy_signals' => 'array'];
    }
}
