<?php

namespace App\Models;

use App\Support\FileKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One file in a workspace's private storage (see App\Support\Files, the only writer). The workspace, the record it
 * belongs to and who stored it are set by trusted code, never from a request. Deleting is soft: the object leaves
 * storage, the row stays.
 *
 * @property string $id
 * @property string $tenant_id
 * @property FileKind $kind
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $uploaded_by_user_id
 * @property Carbon|null $deleted_at
 */
#[Fillable(['kind', 'path', 'mime', 'size', 'sha256'])]
class StoredFile extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected function casts(): array
    {
        return ['kind' => FileKind::class, 'size' => 'integer'];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
