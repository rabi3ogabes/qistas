<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

final class Audit
{
    /**
     * @param  array<string, mixed>  $changes  What changed, never secrets (no passwords, tokens or national IDs).
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $tenantId = null,
        ?string $userId = null,
    ): AuditLog {
        $request = request();

        return AuditLog::create([
            'tenant_id' => $tenantId ?? app(CurrentTenant::class)->id(),
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
