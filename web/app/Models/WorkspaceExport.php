<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A business's books as one file: a nightly copy or a download someone asked for (see App\Exports\ExportService, the
 * only writer). The file itself is compressed and encrypted in `payload`, which is never serialised.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind nightly | manual
 * @property string $format xlsx | csv
 * @property string $status ready | failed
 * @property string|null $payload
 * @property int $size bytes of the file itself
 * @property string|null $sha256
 * @property array<string, int>|null $row_counts
 * @property string $language
 * @property string|null $requested_by_user_id
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
#[Fillable(['kind', 'format'])]
class WorkspaceExport extends Model
{
    use BelongsToTenant, HasUuids;

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['row_counts' => 'array', 'size' => 'integer', 'expires_at' => 'datetime'];
    }

    public function filename(): string
    {
        return 'qistas-'.$this->created_at->format('Y-m-d').($this->format === 'csv' ? '-csv.zip' : '.xlsx');
    }

    public function mime(): string
    {
        return $this->format === 'csv' ? 'application/zip' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /** @return array<string, mixed> what the API and the pages show */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'format' => $this->format,
            'status' => $this->status,
            'size' => $this->size,
            'row_counts' => $this->row_counts ?? [],
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
