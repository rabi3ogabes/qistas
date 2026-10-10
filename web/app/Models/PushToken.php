<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A phone that receives pushes (Win Plan PP9): Firebase's token for one install of the app. Whoever signs in on the
 * phone last receives its pushes; a token Firebase reports gone, or one a person signed out of, is revoked.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $token
 * @property string $platform android | ios | web
 * @property string|null $app_version
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'token', 'platform', 'app_version', 'last_seen_at', 'revoked_at'])]
class PushToken extends Model
{
    use BelongsToTenant, HasUuids;

    public const PLATFORMS = ['android', 'ios', 'web'];

    /**
     * A phone being registered in one workspace leaves any other it was in, so a phone that changed hands never shows the
     * previous person's customers. The only place a token is touched outside its workspace, and only by the token itself.
     */
    public static function releaseElsewhere(string $token, string $tenantId): void
    {
        self::withoutGlobalScope(TenantScope::class)->where('token', $token)->where('tenant_id', '!=', $tenantId)->delete();
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
