<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The receipt sent with a bank transfer request (Win Plan PP13), kept in the database: a picture re-encoded without its
 * hidden data, or a PDF as it came. Written only by App\Billing\ManualPayments; opened only by platform staff.
 *
 * @property string $id
 * @property string $manual_payment_request_id
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $data base64
 * @property Carbon $created_at
 */
class ManualPaymentReceipt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime'];
    }
}
