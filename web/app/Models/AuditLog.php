<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only record of who did what. Written through App\Support\Audit; never edited or deleted. */
#[Fillable(['tenant_id', 'user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip', 'user_agent'])]
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit entries are append-only.'));
    }

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
