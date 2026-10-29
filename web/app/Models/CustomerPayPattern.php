<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The day of the month a customer really pays on (daily-habit wave A, H6), as App\Domain\PayPattern\PayPatternCalculator
 * worked it out from their payments. One row per customer, replaced each time it is worked out again; a customer with
 * too little to say has none. Written only by trusted code from the ledger's own dates, never from request data, and
 * never shown to the customer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property int $day 1 to 28; 28 means the end of the month
 * @property int $spread
 * @property int $months
 * @property int $within percent of the months paid within 3 days of the day
 * @property string $confidence high | medium
 * @property Carbon $computed_at
 */
#[Fillable(['customer_id', 'day', 'spread', 'months', 'within', 'confidence', 'computed_at'])]
class CustomerPayPattern extends Model
{
    use BelongsToTenant, HasUuids;

    /** When it was worked out is the one time that matters; the row is replaced, not edited. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'spread' => 'integer',
            'months' => 'integer',
            'within' => 'integer',
            'computed_at' => 'datetime',
        ];
    }
}
