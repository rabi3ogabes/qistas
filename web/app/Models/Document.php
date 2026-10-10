<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A statement, report or receipt a workspace made (see App\Documents\DocumentService, the only writer). The code is
 * what its QR carries to /verify; that page shows the reference and the total, never a person.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind customer_statement | contract_statement | transactions_report | investor_report | receipt
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $params_hash
 * @property string $verification_code
 * @property string $reference
 * @property string|null $total
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['kind', 'reference', 'total'])]
class Document extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return ['total' => 'decimal:4'];
    }
}
