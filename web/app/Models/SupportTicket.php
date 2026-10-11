<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A question or a problem someone sent from the app (Win Plan PP17): what they wrote, in which language, and the app
 * version and platform it came from. Never a password or a token. Written only by App\Help\SupportTickets.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $category question | problem | missing_data | billing | idea
 * @property string $message
 * @property string|null $language
 * @property string|null $app_version
 * @property string|null $platform android | ios | web
 * @property string $status open | answered | closed
 * @property Carbon|null $first_reply_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 */
class SupportTicket extends Model
{
    use BelongsToTenant, HasUuids;

    public const CATEGORIES = ['question', 'problem', 'missing_data', 'billing', 'idea'];

    public const PLATFORMS = ['android', 'ios', 'web'];

    /** Hours to the first reply the screen promises: sooner for a report of missing data. */
    public const REPLY_HOURS = 24;

    public const MISSING_DATA_HOURS = 4;

    /** @return HasMany<SupportReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(SupportReply::class)->orderBy('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The first reply is due by then. */
    public function replyDue(): Carbon
    {
        return $this->created_at->copy()->addHours($this->category === 'missing_data' ? self::MISSING_DATA_HOURS : self::REPLY_HOURS);
    }

    protected function casts(): array
    {
        return ['first_reply_at' => 'datetime', 'closed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
