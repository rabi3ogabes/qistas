<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sighting of an ErrorIssue. Only the last five per issue are kept (the recorder deletes the oldest when it writes a
 * sixth), and everything in it was cleaned by ErrorScrubber first: no address, no user agent, no cookie, no request body,
 * no user or business. Platform-wide like its issue: no BelongsToTenant, no tenant_id. A row is never changed, so there is
 * no updated_at.
 *
 * @property string $id
 * @property string $issue_id
 * @property Carbon $occurred_at
 * @property int|null $status the HTTP status, when there was one
 * @property string|null $method
 * @property string|null $route the route pattern, never the address with ids or a query string
 * @property string $message scrubbed, at most 500 characters
 * @property string $trace scrubbed, at most 8 KB; empty when there is none
 * @property string|null $version
 * @property string $platform server, web, android or ios
 * @property string|null $device desktop, mobile or tablet
 * @property Carbon $created_at
 */
#[Fillable(['issue_id', 'occurred_at', 'status', 'method', 'route', 'message', 'trace', 'version', 'platform', 'device'])]
class ErrorOccurrence extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @return BelongsTo<ErrorIssue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(ErrorIssue::class, 'issue_id');
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status' => 'integer',
        ];
    }
}
