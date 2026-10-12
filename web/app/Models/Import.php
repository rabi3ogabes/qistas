<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A file of customers being brought in (Win Plan K9): its header row and cells, how its columns are taken, the trial run
 * (the planned rows and their problems) and, once confirmed, what was brought in. The cells and rows are encrypted at
 * rest; the cells are dropped once the import is confirmed. Written only by App\Import.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source xlsx | csv | ai
 * @property string $filename
 * @property list<string> $headers
 * @property list<list<string>> $cells
 * @property array<int, string|null> $mapping column index => field
 * @property list<array<string, mixed>> $rows
 * @property array<string, int|string> $summary
 * @property string $status trial | done
 * @property string|null $created_by_user_id
 * @property string|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
class Import extends Model
{
    use BelongsToTenant, HasUuids;

    protected $hidden = ['cells', 'rows'];

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'cells' => 'encrypted:array',
            'mapping' => 'array',
            'rows' => 'encrypted:array',
            'summary' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
